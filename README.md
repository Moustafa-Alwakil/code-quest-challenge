# Instructor Revenue Ledger

A revenue-sharing back end for a course subscription platform. Students pay once, up front, for a
term. That money is **not** income on the day it arrives — it is a liability the platform works off
month by month, and only as it is earned does it split into platform revenue and instructor
earnings, weighted by how much each instructor was actually watched in that month. Earnings sit on
a hold, then become payable, then get sent to a payment provider that can succeed, fail, or leave
you genuinely unsure which. Every movement is a double-entry posting in an append-only ledger, and
a single command recomputes the whole thing from scratch and tells you if it disagrees.

The design, with its reasoning, is in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). How it was
built with AI assistance, and what was rejected, is in [`docs/AI_USAGE.md`](docs/AI_USAGE.md).

---

## Requirements

| | |
|---|---|
| PHP | 8.2+ (developed on 8.4) |
| MySQL | 8+ — **not SQLite**, see [below](#why-the-tests-need-mysql) |
| Redis | for the queue and the cache |
| Node | for Vite, to build Filament's assets |

---

## Setup

```bash
git clone <repository> instructor-revenue-ledger
cd instructor-revenue-ledger

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
```

Create **both** databases. The test suite truncates and re-migrates its own, so it must not be the
one you are looking at:

```sql
CREATE DATABASE code_quest         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE code_quest_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`phpunit.xml` pins the test database name, host and credentials, so `code_quest_testing` has to
exist under that name before the suite will run. Point `.env` at the other one.

```bash
php artisan migrate --seed
```

That runs `DemoSeeder`: five named instructors, twelve courses, twenty students, and five
deliberately-shaped scenarios (rounding, zero engagement, a mid-term refund, a single-instructor
pool, a below-minimum balance that carries forward). It calls `ledger:verify` on itself before it
finishes and throws if the data it just wrote does not balance.

Then, in separate terminals:

```bash
php artisan queue:work --queue=payouts,default   # payout and accrual jobs
php artisan schedule:work                        # the nightly and monthly sweeps
```

### Admin

Filament, read-only, at **`/admin`**.

| | |
|---|---|
| Email | `admin@revenue.test` |
| Password | `password` |

Two resources: instructors with their balances and payout history, and payout runs with item
counts by status. Nothing in the panel creates, edits or deletes — every number on it is derived,
and the way to change one is to run the command that moves money.

---

## Commands

| Command | What it does |
|---|---|
| `ledger:accrue {--date=} {--chunk=} {--sync}` | Recognize every period whose window has closed: split gross into platform revenue and engagement-weighted instructor earnings, post the ledger transaction, and release earnings past their hold. Refuses a future `--date`; a past one is a legal backfill. |
| `ledger:verify {--instructor=} {--fail-fast}` | Recompute every balance from the ledger and assert eight invariants. Exit 0 clean, 1 if the money is wrong, 2 if `--instructor=` matched nothing. |
| `payouts:run {--run-key=} {--min-amount=} {--dry-run} {--sync}` | Open (or resume) a payout run, reserve every payable balance, dispatch a job per item. `--run-key` defaults to `payout:YYYY-MM`, so re-running within the month resumes rather than opening a second run. |
| `payouts:reconcile {--limit=} {--sync}` | Ask the provider about items whose outcome is still uncertain, on a backing-off ladder. This is what unfreezes money stuck in `provider_in_transit`. |
| `payouts:resolve {item} --as= --reason= {--provider-ref=}` | Move one item out of `needs_review` on a human's decision. `--reason` is required — it is the only path where money moves on a person's word rather than a provider's answer. |
| `refunds:issue {subscription} {--full} {--effective=} {--external-ref=} {--reason=} {--dry-run}` | Record a refund and apply it: truncate the schedule pro-rata, or cancel the term and claw back what was already recognized. |
| `subscriptions:expire` | Cosmetic housekeeping — mark terms whose window has closed. Nothing financial depends on it. |

Every one of them is idempotent. Run any of them twice and the second run writes nothing.

The scheduler (`routes/console.php`) runs `subscriptions:expire` at 00:10, `ledger:accrue` at
00:20, `payouts:run` monthly on the 1st at 03:00, and `payouts:reconcile` every five minutes.

### Scale data

```bash
SCALE_SUBSCRIPTIONS=50000 php artisan db:seed --class=ScaleSeeder
```

Refuses to run against a non-empty `subscriptions` table — it derives ids from MySQL's
consecutive-autoincrement guarantee and is explicitly not idempotent, which is a trade recorded in
the architecture doc rather than hidden. Measured timings are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md#scaling-considerations).

---

## Tests

```bash
php artisan test                       # the default run: Unit, Feature, Concurrency, Chaos
php artisan test --compact
```

The suites are separate because they need different database handling:

```bash
php artisan test --testsuite=Unit          # pure functions, no database
php artisan test --testsuite=Feature       # RefreshDatabase
vendor/bin/pest --group=concurrency        # DatabaseTruncation, two real connections
php artisan test --testsuite=Chaos         # seeded random walk, invariants after every step
vendor/bin/pest --group=soak               # the same walk at 5 000 steps
```

**Run one at a time.** They share a single test database, and two suites racing each other produce
failures that have nothing to do with the code.

Static analysis and formatting:

```bash
vendor/bin/pint
vendor/bin/phpstan analyse            # Larastan, level 10
```

<!-- TODO(submission): replace with the committed screenshot of a full green run. -->
_Test evidence: screenshot to be committed at `docs/evidence/test-suite.png`._

### Why the tests need MySQL

Not preference — the things under test do not exist in SQLite.

Idempotency here is a `UNIQUE` index plus the affected-row count of an `insertOrIgnore`, and
"replay" versus "new posting" is decided by that count. Concurrency is `SELECT ... FOR UPDATE` and
a conditional `UPDATE ... WHERE status = ?` asserting `affected === 1`. The concurrency suite opens
two real connections and interleaves them by hand to prove one blocks on the other. SQLite's
locking, its unique-violation behaviour and its transaction semantics all differ, so a green SQLite
run would be evidence about SQLite, not about this system.

---

## Assumptions

Stated because each one is a place a real deployment would differ, and a reviewer should not have
to guess which were decisions and which were oversights.

- **Single currency (EGP).** Amounts are integer minor units (piastres) end to end. The `currency`
  column exists on every money table for forward compatibility; no conversion is implemented.
- **One up-front payment per subscription.** No instalments, no recurring charge, no dunning.
- **Payments and refunds are recorded facts, not actions.** The gateway already moved the money;
  this system records it, keyed by a `UNIQUE external_ref`. There is no inbound charge provider
  and no `pending` payment state.
- **Engagement is pre-aggregated.** `subscription_period_engagement` holds minutes per
  instructor per subscription-period, seeded here. Raw event ingestion is a separate system, and
  this rollup table is the interface to it.
- **Engagement recorded after a period is recognized is ignored.** The allocation is a decision
  made at a point in time, not a running total.
- **One platform-wide revenue share** (70%), not per-instructor or per-course.
- **UTC day boundaries.** Every period boundary is a true midnight-UTC instant, derived from a
  calendar date rather than by converting an instant.
- **Monthly accrual periods and a monthly payout schedule.**
- **A minimum payout threshold** (EGP 100), below which a balance carries forward.
- **A 7-day hold** on earnings before they become payable.
- **No student-facing application.** The brief's story starts after the student has paid; the only
  UI is the read-only admin panel.

Every policy figure above is a dial in `config/revenue.php`, overridable from `.env` — see
`.env.example`, which lists all of them.

---

## Where to read next

| | |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Decisions, allocation strategy, idempotency, timeout handling, scale, limitations |
| [`docs/AI_USAGE.md`](docs/AI_USAGE.md) | How this was built with AI, and what was rejected |
| [`docs/PLAN.md`](docs/PLAN.md) | The original analysis and decision log, written before any code |
| [`docs/features/`](docs/features/) | Twelve feature specifications, and fifty numbered refinements the build made to the plan |
| [`docs/AI_WORKFLOW.md`](docs/AI_WORKFLOW.md) | The agent environment: rules, skills, sub-agents, and the arch tests that bind them |
| [`docs/VIDEO.md`](docs/VIDEO.md) | The walkthrough runbook, with exact commands per demo |

`docs/PLAN.md` and `docs/features/` are kept deliberately. They are the planning record, they show
where the design changed under contact with the code, and `AI_USAGE.md` leans on them as evidence.
