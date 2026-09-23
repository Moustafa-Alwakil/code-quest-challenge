# F11 — Student Flow: Auth, Catalog, Enrolment, Checkout, My Subscription

> **Day:** 6 — **discretionary.** Cut line: end of Day 5 (PLAN §16)
> **Depends on:** F04, F09 · **Implements:** D‑11 · **Plan refs:** §13.2
> **Grade areas:** none directly — supports "Who We're Looking For" (Livewire, Alpine, Tailwind)

## Goal

A minimal Livewire 3 + Alpine + Tailwind slice where money enters the system through a checkout
held to the **same standard as payouts**.

## Hard scope — a closed list of five screens

| Route | Component | Access |
|---|---|---|
| `/register`, `/login` | Breeze (Livewire stack), as generated | guest |
| `/courses` | `CourseCatalog` — paginated, instructor name | public |
| enrol / un-enrol (on catalog or course card) | `EnrolButton` — Livewire action + Alpine optimistic toggle | auth + live subscription |
| `/plans` → `/checkout/{plan}` | `Checkout` — plan cards, confirm, pay | auth, no live subscription |
| `/subscription` | `MySubscription` — plan, term, days left, status, *Cancel* | auth |

Custom screens are class-based Livewire components (easier to test and to explain than Volt).
Filament stays at `/admin` with its own login; Breeze serves students.

**The line:** if it does not create, end or refund a subscription, it is not built.

## Checkout — step by step

1. **Mount:** generate a checkout intent key (UUID) in a `#[Locked]` component property, so the
   browser cannot tamper with it.
2. **Confirm → `StartCheckout`** (one transaction): create subscription `pending_payment` and
   payment `pending` with `idempotency_key = intent key`.
   - Same key twice (double-click, double-submit, retry) → UNIQUE `payments.idempotency_key`
     → returns the existing payment.
   - Different key, same student (second tab, second plan) → UNIQUE `active_user_id` (F04)
     rejects it.
3. **Commit**, then `ChargeProvider::charge(key, amount)` — outside any transaction.
4. **Outcome:**

| Outcome | Result | UI |
|---|---|---|
| succeeded | `SubscribeStudent` (F04) activates; `term_start = captured_at` | redirect to `/subscription` |
| failed | payment `failed`, subscription `payment_failed` (frees the live slot) | error; may retry with a new key |
| timeout / pending | payment `unknown`; subscription stays `pending_payment` | "Confirming your payment…" with `wire:poll` every 5s |

5. **`payments:reconcile`** (every minute) resolves `unknown` charges via `getStatus` — the same
   ladder, grace window and 24h `needs_review` escalation as F08.

**Alpine's role:** disable the Pay button and show a spinner on click. Comfort, never the
guarantee — the unique index is the guarantee. *The UI-layer parallel to D‑10.*

## `ChargeProvider` contract

`charge(key, amountMinor, currency, customerRef)` → `ChargeResult` (`succeeded` / `failed` /
`pending` / `not_found`, `externalRef`, `capturedAt`) or `ProviderTimeoutException` ·
`getStatus(key)` · `refund(key, amountMinor)` (always succeeds in scope).

`MockChargeProvider`: the same three outcomes plus delayed confirmation, persisted in
`mock_charge_transactions` (UNIQUE key) with the same dedup semantics as F07's mock. Separate
interface from `PaymentProvider` — charging a customer and paying an instructor are different
operations with different failure and reversal rules.

## My subscription — Cancel

- **Cancel & refund unused time** opens an Alpine confirmation modal showing the pro-rata refund
  amount **before** confirming. The preview is computed by F09's code path in dry-run mode, so the
  number shown is the number applied.
- Confirm → `ApplyProrataRefund` (F09) with its own idempotency key.
- Refused while the payment is `unknown`.

## Enrolment

- Requires a live subscription (policy check).
- UNIQUE `(user_id, course_id)`.
- Un-enrol deletes the row — enrolments are access, not money, so a hard delete is fine here
  (unlike anything on the ledger).
- Alpine optimistic toggle; Livewire rolls it back on error.

## Edge cases

| Case | Expected |
|---|---|
| double-click Pay | one subscription, one payment, one charge |
| two tabs, two plans | second rejected by the live-subscription constraint |
| browser back after paying | intent key already succeeded → shows existing subscription |
| user closes tab during timeout | reconcile still resolves; term starts at `captured_at` |
| session expires mid-checkout | re-login; pending intent resolves via reconcile |
| cancel twice | F09 unique refund → no-op |

## Acceptance criteria / tests

- [ ] **Double-submit:** two concurrent requests, same key → 1 subscription, 1 payment,
      1 schedule, 1 charge. Lives next to F06's "run twice" test so they read as a pair.
- [ ] Different keys, same student → second rejected
- [ ] Charge timeout → not active; reconcile → active with `term_start = captured_at`
- [ ] Charge failure → `payment_failed`; a new checkout succeeds
- [ ] Cancel: preview amount = applied amount
- [ ] Smoke: pages render; guests redirected; admin/student separation
- Smoke-level only beyond the above — **no time taken from ledger tests.**

## Escape hatch

If checkout is still fighting you by midday on Day 6: ship Breeze + one Subscribe page (steps
1–4, no polling UI) and stop.

## Out of scope

Password reset / email verification beyond Breeze defaults, profile editing, instructor pages,
video playback, progress tracking, quizzes, certificates, search, reviews.

## Demo hook

Demo 0 (~60s): sign up → enrol → buy annual → show the twelve periods. Scenario 6: click Cancel.
