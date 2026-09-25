# F04 — Subscriptions, Payments & Accrual Schedule

> **Day:** 2 · **Depends on:** F01, F02, F03 · **Implements:** D‑1 (scheduling half)
> **Plan refs:** §4 (D‑1), §5.2, §5.3, §7 (first box)
> **Grade areas:** Correctness (25%), System design (20%)

## Goal

Turn a captured up-front payment into a subscription with a complete, exact accrual schedule and
a deferred-revenue liability on the ledger — through **one entry point** used by seeders, tests and
any future ingestion path.

## Payments are recorded facts

The brief's story begins *after* the student has paid. This system does not take card payments:
it records each captured payment **once**, keyed by the gateway's reference, and derives everything
else from it. There is no checkout, no `pending` payment, and no inbound provider call. *(R25.)*

## Data model

### `subscriptions`

`user_id`, `plan_id`, `status`, `term_start` (= the payment's `captured_at`), `term_end`,
`term_days`, `price_minor` (snapshotted from the plan at purchase), `currency`, `canceled_at`.

- `status`: `active`, `refunded`, `expired`
- INDEX `(status, term_end)`

### `payments`

`subscription_id`, `external_ref`, `amount_minor`, `currency`, `captured_at`.

- **UNIQUE** `subscription_id` — one up-front payment per subscription
- **UNIQUE** `external_ref`, **NOT NULL** — the gateway's charge id. Recording the same payment
  twice is a no-op. (NOT NULL matters: a nullable column in a unique key disables it — R4.)

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

Input: user id, plan id, `external_ref`, amount, `captured_at`. Used by seeders and tests, and by
any future ingestion path (a gateway webhook, an import). One DB transaction:

1. Look up the payment by `external_ref`. Found → return its subscription. **A replay writes
   nothing.**
2. Assert the amount and currency equal the plan's price; reject otherwise.
3. Create the subscription `active`; `term_start = captured_at`; `term_end`, `term_days` from the
   anchor; price snapshotted.
4. Insert the payment. A concurrent duplicate hits UNIQUE `external_ref`; that transaction rolls
   back, and its retry takes step 1's replay path.
5. Post `payment_received`: DR `platform_cash[0]` +price, CR `deferred_revenue[sub]` −price.
6. `AccrualScheduler::scheduleFor(sub)`.

### `ExpireSubscriptions` (daily)

Marks `active` subscriptions past `term_end` as `expired`. **Cosmetic only** — money is driven by
accrual periods, never by subscription status. Worth saying in the video.

## Rules

- **Term starts at capture.** `term_start` is the gateway's `captured_at`, not the time the fact
  was recorded, so a late-arriving record does not shorten the student's term.
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
| same payment recorded twice | replay: one subscription, one ledger transaction, one schedule |
| same payment recorded concurrently | UNIQUE `external_ref`: one wins, the other replays |
| amount differs from plan price | rejected, nothing written |
| plan price changed after purchase | no effect |

## Acceptance criteria

- [ ] For all 3 plans × all 366 start dates of a leap year: `Σ gross === price` and no drift
- [ ] `SubscribeStudent` twice with the same `external_ref` → one of everything
- [ ] After recording: `deferred_revenue[sub] = price`; ledger balanced

## Tests

- Unit: boundary computation (Jan 31, leap years, month ends); Σ-gross dataset above
- Integration: replay idempotency; amount mismatch rejected; ledger entries; `ledger:verify` green
- Concurrency group: two connections record the same `external_ref` → one subscription

## Demo hook

Show a seeded annual subscription's twelve periods and their uneven-but-exact gross amounts
summing to EGP 3 000.00 — the setup for every failure demo that follows.
