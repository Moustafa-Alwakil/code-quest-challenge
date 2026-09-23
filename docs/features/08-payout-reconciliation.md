# F08 — Payout Reconciliation

> **Day:** 4 · **Depends on:** F07 · **Implements:** D‑8 (resolve side)
> **Plan refs:** §8.3, §8.4, §10 · **Required proof #3**
> **Grade areas:** Failure handling & idempotency (20%)

## Goal

Resolve every payout whose outcome is uncertain by **asking the provider, never by guessing** —
and hand it to a human, with the money still frozen, when the provider can't answer.

## Components

### `payouts:reconcile {--limit=}`

Scheduled every 5 minutes, `withoutOverlapping()`. Two sweeps, both on INDEX
`(status, next_check_at)`:

| Sweep | Selects | Action |
|---|---|---|
| uncertain | `status IN (submitted, unknown) AND next_check_at <= now` | dispatch `ReconcilePayoutItemJob` |
| stranded | `status = reserved AND created_at < now − 30 min` (the job was lost) | re-dispatch `ProcessPayoutItemJob` — safe: CAS + same key |

### `ReconcilePayoutItemJob(itemId)` — `ShouldBeUnique`

1. Terminal → return.
2. `getStatus(key)`; append a `payout_attempts` row (operation `status`).
3. Act on the answer:

| Provider says | Action |
|---|---|
| `succeeded` | `SettlePayoutItem` (shared with F07) |
| `failed` | `ReversePayoutItem` (shared with F07) |
| `pending` | reschedule on the ladder: 1m → 5m → 30m → 2h → 6h |
| `not_found`, within grace (15 min since `submitted_at`) | reschedule |
| `not_found`, after grace | CAS back to `reserved`; dispatch `ProcessPayoutItemJob` (resend, **same key**) |

4. Still unresolved 24h after first `submitted_at` → `needs_review`.
5. Re-evaluate `FinalizePayoutRun` (F06) for the item's run.

### `payouts:resolve {item} --as=succeeded|failed --reason=` *(stretch)*

Operator command for `needs_review` items only. Applies the shared settle/reverse action and
records a `payout_attempts` row with operation `manual` and the reason. The read-only Filament
screen (F10) shows these items; resolving them happens here, audited.

## Rules

- **`unknown` never becomes `failed` by the passage of time.** Only the provider can say failed.
- **`not_found` is not proof.** A provider's status API can lag its transfer API; "not found"
  straight after a timeout may mean "not visible yet". Resend only after the grace window — and
  even then with the same key, so provider dedup protects us if it did exist.
- `needs_review` money stays in `provider_in_transit` — visible as *in flight* in Filament, never
  auto-released, never auto-resent.

## Edge cases

| Case | Expected |
|---|---|
| provider reports `pending` forever | `needs_review` at 24h, money reserved |
| reconcile runs concurrently with a late F07 retry | CAS decides the transition; ledger unique key blocks a double settle |
| reconcile command runs twice at once | ShouldBeUnique + CAS + ledger unique → one settlement |
| timeout on a transfer that actually failed | reconcile → `failed` → balance back to `available` |

## Acceptance criteria / tests

- [ ] **Required proof #3:** scripted timeout-after-success → item `unknown`, money reserved, no
      `payout_settled` entry → run reconcile → `succeeded`; `transferCount = 1`; two
      `payout_attempts` rows (transfer + status); `ledger:verify` green
- [ ] Delayed confirmation: `pending` twice, `succeeded` on the third check
- [ ] Timeout on a real failure → reconcile → `failed` → available restored
- [ ] `not_found` within grace → rescheduled; after grace → resent once; `transferCount = 1`
- [ ] 24h unresolved → `needs_review`, money reserved
- [ ] Stranded `reserved` item is re-dispatched and pays once
- [ ] Concurrent reconciliation → one settlement

## Demo hook

Scenario 4 (timeout) and scenario 5 (success with delayed confirmation). In Filament: the item
sits in `unknown` (amber), `payouts:reconcile` runs, it flips to `succeeded`; the attempts list
shows **two interactions and one transfer**.
