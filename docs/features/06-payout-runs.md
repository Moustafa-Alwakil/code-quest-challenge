# F06 — Payout Runs & Balance Reservation

> **Day:** 3 · **Depends on:** F03, F05 · **Implements:** D‑10 (payout side)
> **Plan refs:** §8.1–§8.3, §9 rows 5–7 · **Required proof #1**
> **Grade areas:** Failure handling & idempotency (20%), Correctness (25%)

## Goal

A scheduled or manual `payouts:run` that decides who is paid how much, and moves that money out
of `available` **atomically, before any provider call** — so running it twice, or on two servers
at once, can never pay twice.

## Data model

### `payout_runs`

`run_key`, `scheduled_for`, `status`, `item_count`, `total_minor`, `started_at`, `finished_at`.

- `status`: `open`, `dispatched`, `completed`, `completed_with_pending`
- **UNIQUE** `run_key`

### `payout_items`

`payout_run_id`, `instructor_id`, `amount_minor`, `currency`, `status`, `idempotency_key`,
`provider_reference`, `attempts`, `next_check_at`, `submitted_at`, `settled_at`, `last_error`.

- `status`: `reserved`, `submitted`, `succeeded`, `failed`, `unknown`, `needs_review`
- **UNIQUE** `(payout_run_id, instructor_id)` · **UNIQUE** `idempotency_key`
- INDEX `(status, next_check_at)` · INDEX `(instructor_id, created_at)`

Items are **born `reserved`**: creation and reservation happen in one transaction, so there is no
`pending` state to strand. *(Small refinement of the PLAN §8.1 state machine.)*

## Components

### `payouts:run {--run-key=} {--dry-run} {--min-amount=} {--sync}`

1. **Optimization:** `Cache::lock("payouts:run:{key}")`. Not acquired → print "run in progress",
   exit 0. Not a correctness guard.
2. `ReleaseMaturedEarnings` (F05) so availability is current.
3. `CreateOrResumePayoutRun`: `insertOrIgnore` by `run_key`, then load it. Already `completed` →
   print its summary and exit.
4. Keyset over `instructor_balances WHERE available_minor >= min`, ascending instructor id →
   `ReserveInstructorBalance` for each.
5. Dispatch `ProcessPayoutItemJob` for **every** item of this run still in `reserved` — including
   items left by a previous crashed invocation. `Bus::batch()`, `afterCommit()`; the batch's
   `finally` calls `FinalizePayoutRun`.
6. `--dry-run`: no writes at all; prints the would-pay table from current balances.

Default `run_key` = `payout:YYYY-MM`.

### `ReserveInstructorBalance` — one DB transaction

1. `SELECT … FROM instructor_balances WHERE instructor_id = ? FOR UPDATE`.
2. `amount = available_minor`. Below minimum (or negative) → skip.
3. `insertOrIgnore` payout item `(run, instructor, amount, reserved, new uuid)`. Affected 0 →
   this instructor already has an item in this run → skip.
4. Post `payout_reserved` (reference: the item): DR `instructor_payable[i]` +amount,
   CR `provider_in_transit[i]` −amount. Snapshot: `available −amount`, `reserved +amount`.
5. Commit.

### `FinalizePayoutRun`

Counts item statuses. All terminal (`succeeded` / `failed`) → `completed`. Any `unknown`,
`submitted` or `needs_review` → `completed_with_pending`. Re-evaluated by F08 as items resolve.

### Schedule

Monthly on the 1st at 03:00, `withoutOverlapping()->onOneServer()`. Both need the shared Redis
cache — both are optimizations.

## Rules — two guarantees for two scopes

| Scope | Guarantee |
|---|---|
| **Within one run** | UNIQUE `(run, instructor)` → at most one item per instructor, however many times the command runs |
| **Across runs** | Reservation already moved the money out of `available`, so a new run key sees only genuinely new earnings |

Explaining *why both are needed* is a strong review answer: the unique key alone would let a
second run key pay the same balance again; reservation alone would let two concurrent
invocations of the same run race.

Further rules:

- Earnings maturing after an instructor's item was created wait for the next run — including on
  a manual re-trigger with the same key.
- Pays the **full available balance**, not per-allocation amounts. The ledger records exactly
  what was reserved.
- The `FOR UPDATE` on the snapshot row serializes against concurrent recognition and clawbacks
  for that instructor.

## Edge cases

| Case | Expected |
|---|---|
| run twice sequentially, same key | second creates nothing, dispatches nothing new |
| two processes concurrently, lock unavailable | unique `run_key` + unique item → one payment |
| crash after reserving half the instructors | rerun resumes: existing items skipped, rest reserved, all `reserved` items dispatched |
| clawback lands between reserve and send | the item still pays the reserved amount (it was owed at reservation); `available` goes negative and nets next time |
| instructor with no snapshot row | not selected |

## Acceptance criteria / tests

- [ ] **Required proof #1:** run twice with the same key (sync queue, scripted success) →
      one run, one item per eligible instructor, provider transfer count = 1 per item,
      `paid_minor` incremented once, `ledger:verify` green
- [ ] New run key with no new earnings → no items
- [ ] **Concurrency:** two interleaved connections attempt the same run/item → one item
- [ ] **Redis-down variant:** bind a lock that always acquires → still exactly one payment.
      *This is the test that backs the "if Redis disappeared" claim.*
- [ ] Below-minimum and negative balances skipped and carried
- [ ] Resume after a simulated crash mid-reservation
- [ ] `--dry-run` writes nothing (row counts unchanged)

## Demo hook

Scenario 1 (run twice) and scenario 2 (two terminals at once). Show the unique index, not the lock.
