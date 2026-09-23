# F04 — Subscriptions, Payments & Accrual Schedule

> **Day:** 2 · **Depends on:** F01, F02, F03 · **Implements:** D‑1 (scheduling half)
> **Plan refs:** §4 (D‑1), §5.2, §5.3, §7 (first box)
> **Grade areas:** Correctness (25%), System design (20%)

## Goal

Turn a successful up-front payment into a subscription with a complete, exact accrual schedule
and a deferred-revenue liability on the ledger — through **one entry point** used by seeders,
tests and checkout alike.

## Data model

### `subscriptions`

`user_id`, `plan_id`, `status`, `term_start` (set at capture), `term_end`, `term_days`,
`price_minor` (snapshotted from the plan at purchase), `currency`, `canceled_at`, `active_user_id`.

- `status`: `pending_payment`, `active`, `payment_failed`, `refunded`, `expired`
- `active_user_id`: generated column = `user_id` when status is `pending_payment` or `active`,
  otherwise NULL. **UNIQUE.** Enforces one live subscription per student at the database level.
  (Here NULLs being distinct is exactly the behaviour we want.) Used by F11.
- INDEX `(status, term_end)`

### `payments`

`subscription_id`, `idempotency_key`, `external_ref`, `amount_minor`, `currency`, `status`,
`captured_at`, `next_check_at`, `attempts`, `last_error`.

- `status`: `pending`, `succeeded`, `failed`, `unknown`
- **UNIQUE** `subscription_id` — one payment per subscription; a failed charge ends that
  subscription and a retry starts a new checkout
- **UNIQUE** `idempotency_key` — double-submit guard (F11)
- **UNIQUE** `external_ref` — provider charge id; nullable while pending (multiple NULLs intended)

### `accrual_periods`

`subscription_id`, `sequence` (1…12), `period_start` (DATE), `period_end` (DATE, **exclusive**),
`days`, `gross_minor`, `pool_minor` and `platform_minor` (set at recognition), `status`,
`recognized_at`.

- `status`: `scheduled`, `recognized`, `cancelled`
- **UNIQUE** `(subscription_id, period_start)`; **UNIQUE** `(subscription_id, sequence)`
- INDEX `(status, period_end)` — the recognition sweep (F05)

## Components

### `AccrualScheduler::scheduleFor(subscription)`

1. Boundaries `b_k = term_start_date + k months` for `k = 0 … n`, each computed **from
   `term_start`**, not chained from the previous boundary, using no-overflow month addition.
2. Periods are half-open `[b_k, b_{k+1})`; `days = b_{k+1} − b_k`.
3. Per-period gross = `Allocator::largestRemainder(price, days keyed by sequence)`.
4. Assert `Σ gross === price`; throw otherwise.
5. `insertOrIgnore` — re-running is a no-op.

Why not chained: chaining turns Jan 31 → Feb 28 → Mar 28 → … and drifts permanently. Computing
from the anchor gives Jan 31 → Feb 28 → Mar 31 → Apr 30.

### `SubscribeStudent` — the single entry point

Used by F11's checkout confirmation, by seeders, and by tests. One DB transaction:

1. CAS payment `pending → succeeded` (or create an already-captured payment for seeders). If the
   CAS affects 0 rows, this activation already happened — return the existing subscription.
2. Subscription → `active`; `term_start = captured_at`; `term_end`, `term_days` from the anchor.
3. Post `payment_received`: DR `platform_cash[0]` +price, CR `deferred_revenue[sub]` −price.
4. `AccrualScheduler::scheduleFor(sub)`.

### `ExpireSubscriptions` (daily)

Marks `active` subscriptions past `term_end` as `expired`. **Cosmetic only** — money is driven by
accrual periods, never by subscription status. Worth saying in the video.

## Rules

- **Term starts at capture, not at checkout intent.** A delayed payment confirmation (F11) must
  not shorten the student's term. `captured_at` comes from the provider.
- **Dates:** periods are DATEs in UTC. A purchase at 23:00 counts from that date. Cairo-local
  business days are a documented limitation.
- Price is snapshotted — later plan price changes do not affect existing subscriptions.
- The schedule is written once and never regenerated. Only F09 may truncate or cancel periods.

## Edge cases

| Case | Expected |
|---|---|
| Jan 31 annual start | Feb 28 (29 in leap years), Mar 31, Apr 30 … no drift |
| annual term spanning Feb 29 | 366 term days; still Σ = price |
| monthly plan | one period, gross = price |
| price not divisible by days | largest remainder; earlier sequences win ties |
| activation called twice | CAS no-op; one subscription, one ledger txn, one schedule |
| plan price changed after purchase | no effect |

## Acceptance criteria

- [ ] For all 3 plans × all 366 start dates of a leap year: `Σ gross === price` and no drift
- [ ] `SubscribeStudent` twice with the same payment → one of everything
- [ ] After activation: `deferred_revenue[sub] = price`; ledger balanced

## Tests

- Unit: boundary computation (Jan 31, leap years, month ends); Σ-gross dataset above
- Integration: activation idempotency; ledger entries; `ledger:verify` green;
  one-live-subscription constraint rejects a second active subscription for the same student

## Demo hook

Demo 0: after checkout, show the twelve periods and their uneven-but-exact gross amounts summing
to EGP 3 000.00.
