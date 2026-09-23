# F07 — Provider Contract, Mock Provider & Payout Execution

> **Day:** 4 · **Depends on:** F06 · **Implements:** D‑8 (send side), D‑10
> **Plan refs:** §8.2, §8.4, §8.5, §9 row 8 · **Required item 4 · Required proof #2**
> **Grade areas:** Failure handling & idempotency (20%)

## Goal

Send each reserved payout to the external provider **effectively once** — at-least-once delivery
made safe by an idempotency key the provider honours — and map every provider outcome, including
"we don't know", to a correct money state.

## Provider contract (`PaymentProvider`)

| Operation | Returns |
|---|---|
| `transfer(idempotencyKey, accountRef, amountMinor, currency)` | `TransferResult`, or throws `ProviderTimeoutException` |
| `getStatus(idempotencyKey)` | `TransferResult` |

`TransferResult`: `status` (`succeeded` / `failed` / `pending` / `not_found`),
`providerReference`, `failureCode`, `processedAt`.

| Exception | Meaning | Safe to resend blindly? |
|---|---|---|
| `ProviderTimeoutException` | request may have been processed — **outcome unknown** | No |
| `ProviderUnavailableException` | request provably not accepted (e.g. connection refused before send) | Yes, with the same key |

## Mock providers

**`mock_provider_transfers` table** — simulates the provider's own database: `idempotency_key`
(UNIQUE), `account_ref`, `amount_minor`, `status`, `provider_reference`, `confirm_after_checks`,
`status_checks`. Persistent because "discover the real result later" must work from a different
worker process — an in-memory mock cannot model that.

**`RandomMockProvider`** (demo / manual runs)

- On `transfer`: if the key exists → return the stored result. **It never transfers twice for
  one key** — this models real provider-side dedup.
- Otherwise roll (probabilities in config):

| Outcome | Behaviour | Brief |
|---|---|---|
| success | records success, returns it | ✔ |
| permanent failure | records failure, returns it | ✔ |
| timeout after success | records success, **then throws** `ProviderTimeoutException` | ✔ |
| delayed confirmation | records `pending`; flips to success after N status checks | added for video scenario 5 |

**`ScriptedMockProvider`** (tests) — outcomes queued per test; same persistence and dedup.
Exposes `transferCount(key)` — the source of truth for "the provider moved money once".

Selected by `config('revenue.payout_provider')`.

## `ProcessPayoutItemJob(payoutItemId)`

Queue `payouts` · `tries = 5` · backoff 10s / 60s / 5m / 15m with jitter ·
`ShouldBeUnique` on item id · `WithoutOverlapping` middleware on item id.
(Unique and overlap guards are optimizations.)

1. Load the item. Terminal (`succeeded`, `failed`, `needs_review`) → return.
2. **First attempt** (`reserved`): CAS `reserved → submitted`, set `submitted_at`, `attempts + 1`,
   `next_check_at = now + 10 min`. **Commit** — the intent is durable before the network call.
   **Retry** (`submitted` or `unknown`): call `getStatus` first.
   Definitive → apply it (step 4) and return. `not_found` → continue to send with the same key.
   `pending` → leave it to reconciliation, return.
3. `transfer(item.idempotency_key, …)` — **outside any DB transaction**.
4. Apply the outcome — each in its own transaction, CAS from `submitted` / `unknown`:

| Outcome | New status | Ledger (reference: the item) | Snapshot |
|---|---|---|---|
| succeeded | `succeeded` | `payout_settled`: DR `provider_in_transit[i]`, CR `platform_cash[0]` | `reserved −a`, `paid +a` |
| failed | `failed` | `payout_reversed`: DR `provider_in_transit[i]`, CR `instructor_payable[i]` | `reserved −a`, `available +a` |
| timeout / pending | `unknown` | none — money stays reserved | none |
| `ProviderUnavailableException` | stays `submitted` | none | rethrow → queue retry |

   On `unknown`: set `next_check_at = now + 1 min`, dispatch `ReconcilePayoutItemJob` delayed.
5. Append a `payout_attempts` row: `attempt_no`, operation (`transfer` / `status`), request and
   response summaries, outcome, duration.

**`failed(Throwable)`** — CAS any non-terminal status → `needs_review`. Money stays reserved.
Never automatically back to `reserved`.

`SettlePayoutItem` and `ReversePayoutItem` are shared actions, reused by F08 — one
implementation of each money movement.

## Rules

- **Never hold a DB transaction across the provider call.**
- **Status-first on retry.** Robust even against a provider whose dedup is weaker than our mock's.
- An outcome arriving for an item already terminal is recorded in `payout_attempts` and changes
  nothing — late and duplicate responses are harmless.
- **Conflicting outcomes** (provider says `failed` after we recorded `succeeded`) → `needs_review`
  and an error log. Never auto-reverse settled money.

## Edge cases

| Case | What saves us |
|---|---|
| worker SIGKILL'd between CAS and call | item stays `submitted`; F08 picks it up at `next_check_at` |
| SIGKILL after provider success, before recording | same; `getStatus` → `succeeded` → settle once |
| job dispatched twice | ShouldBeUnique, then CAS, then provider dedup |
| retry after a DB error that followed provider success | status-first path settles without resending |

## Acceptance criteria / tests

- [ ] **Required proof #2a:** handle the same job twice → 1 transfer, 1 `payout_settled`
- [ ] **Required proof #2b:** provider succeeds, then a simulated crash before recording → retry
      → `getStatus` → settled; `transferCount = 1`
- [ ] `failed()` → `needs_review`, money still reserved, `ledger:verify` green
- [ ] Outcome mapping: success; permanent failure (balance back to `available`); timeout
      (`unknown`, reserved, reconcile dispatched)
- [ ] Late duplicate response on a terminal item → no state change
- [ ] **No DB transaction is open during `transfer`** — `ScriptedMockProvider` asserts
      transaction level = 0 when called. A one-line test for the most important ordering rule.

## Demo hook

Scenario 3 (worker retry after failure) and scenario 4 (provider timeout).
