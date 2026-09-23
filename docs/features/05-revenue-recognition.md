# F05 — Revenue Recognition, Allocation & Hold Release

> **Day:** 2 · **Depends on:** F01–F04 · **Implements:** D‑1 (recognition), D‑2, D‑3, D‑6
> **Plan refs:** §4, §7 · **Grade areas:** Correctness (25%), System design (20%)

## Goal

Every day, turn each closed accrual period into platform revenue plus instructor earnings,
divided by engagement — idempotently, at 500k-subscription scale — and release earnings from
hold as they mature.

## Data model

### `earning_allocations`

`accrual_period_id`, `instructor_id`, `weight_units`, `amount_minor`, `available_at`,
`released_at`, `clawed_back_at`, `created_at`.

- **UNIQUE** `(accrual_period_id, instructor_id)`
- INDEX `(released_at, available_at)` — maturation sweep
- INDEX `instructor_id`

## Components

### `ledger:accrue {--date=today} {--chunk=1000} {--sync}`

1. Refuse a `--date` in the future — you cannot recognize time that hasn't happened.
2. Keyset over `accrual_periods WHERE status = scheduled AND period_end <= date`, by id.
3. One `AccruePeriodsChunkJob` per chunk in a `Bus::batch()`; `--sync` runs inline (tests, demo).
4. Finish by running `ReleaseMaturedEarnings`.

### `RecognizeAccrualPeriod` — one DB transaction per period

1. **CAS:** `UPDATE accrual_periods SET status = recognized, recognized_at = now
   WHERE id = ? AND status = scheduled`. Affected 0 → roll back and return (already recognized,
   or cancelled by a refund).
2. Load engagement rows for `(subscription_id, period_start)` with `units > 0`.
3. `[pool, platform] = RevenueSplit(gross, instructor_share_bps)`.
   **No engagement (D‑3):** `pool = 0`, `platform = gross`.
4. `shares = Allocator::largestRemainder(pool, units keyed by instructor_id)`; drop zero shares.
5. Insert `earning_allocations` with `available_at = period_end + hold_days`.
6. Post `period_recognized` (reference: the period):
   DR `deferred_revenue[sub]` +gross · CR `platform_revenue[0]` −platform ·
   CR `instructor_payable[i]` −shareᵢ for each instructor.
7. Snapshot deltas per instructor (ascending id): `earned +shareᵢ`, `held +shareᵢ`.
8. Store `pool_minor`, `platform_minor` on the period. **Commit.**

### `ReleaseMaturedEarnings`

Runs at the end of `ledger:accrue` and at the start of `payouts:run`. Chunked; per chunk, one
transaction:

1. Lock allocations `WHERE released_at IS NULL AND clawed_back_at IS NULL AND available_at <= now`
   (`FOR UPDATE`).
2. Set `released_at = now`.
3. Aggregate per instructor; snapshot `held −x`, `available +x`.

Idempotent: a released row no longer matches the WHERE.

## Decisions pinned down here

- **No intermediate `recognizing` status.** The CAS and all writes share one transaction, so a
  crash rolls the status back too and the next run picks the period up. *(Refines PLAN §7, which
  sketched a `recognizing` state that a crash could strand.)*
- **The hold is a policy overlay, not a ledger account.** The money is owed from the moment of
  recognition; the hold only controls *when it becomes payable*. Keeping it off the ledger avoids
  a second pair of entries per allocation — tens of millions of extra rows — for information the
  allocation row already carries. `ledger:verify` recomputes `held` from allocation rows (F03).
- **Single platform-wide revenue share.** A per-instructor override changes the order of
  operations (allocate gross by weight first, then apply each instructor's rate, platform absorbs
  each floor). Out of scope; documented as an extension. *(Refines PLAN §5.1, which listed an
  optional override.)*
- **`zero_engagement_policy`** exists in config to make the D‑3 dial visible. Only
  `platform_retains` is implemented; `split_enrolled` is documented, not built.

## Scale note

Periods close on each subscription's monthly anniversary, so 500k subscriptions spread to
~17k recognitions per day — not 500k on the 1st. Recognition load is naturally flat.
**Trade-off:** one transaction per period is simplest to reason about; one per chunk with bulk
inserts is faster. Start per-period; measure with `ScaleSeeder`; say which you chose and why.

## Edge cases

| Case | Expected |
|---|---|
| zero engagement | platform gets gross; no allocations; ledger balanced |
| one instructor | whole pool |
| pool smaller than instructor count (pool 2, 5 instructors) | three instructors get 0 → no rows for them |
| two servers recognize the same period | CAS: one wins, the other no-ops |
| period cancelled by a refund | skipped — not `scheduled` |
| engagement arrives after recognition | ignored (late-data policy, F02) |
| `--date` in the past | backfill — allowed |
| `--date` in the future | refused |

## Acceptance criteria

- [ ] `ledger:accrue` × 3 → identical allocations, ledger rows and snapshot
- [ ] For every recognized period: `platform + Σ allocations = gross`
- [ ] Earnings invisible to payouts before `available_at`, visible after; release twice → no-op

## Tests

- Idempotency: accrue three times
- Concurrency: two connections recognize the same period → one wins
- Rounding: pool splitting 3/3/3 → 334 / 333 / 333
- D‑3: zero engagement → platform retains everything
- Crash: throw after step 5 via a test double → full rollback → rerun succeeds
- Hold: time-travel across `available_at`; release idempotency
- Future date refused

## Demo hook

Scenario 7 (rounding) and the D‑3 zero-engagement case.
