# F02 — Catalog & Seed Data

> **Day:** 1 (catalog) · completed after F04 (subscriptions in `DemoSeeder`)
> **Depends on:** F01 · **Implements:** inputs for D‑2 · **Plan refs:** §5.1, §12
> **Grade areas:** Laravel implementation & data integrity (15%)

## Goal

The minimum non-money data the money core needs as input — instructors, courses, plans,
students, enrolments, engagement — plus deterministic seeders for tests and the video.

## Scope

**In:** migrations, models, factories, seeders for the tables below.
**Out:** course content, lessons, media, instructor accounts/login.

## Data model

| Table | Columns | Constraints |
|---|---|---|
| `instructors` | `name`, `email`, `payout_account_ref` (mock), `status` (active / suspended) | UNIQUE `email` |
| `courses` | `instructor_id`, `title`, `slug`, `published_at` | UNIQUE `slug`; INDEX `instructor_id` |
| `plans` | `key` (monthly / quarterly / annual), `name`, `interval_months` (1 / 3 / 12), `price_minor`, `currency`, `is_active` | UNIQUE `key` |
| `users` | Laravel default + `is_admin` (Filament access) | — |
| `enrolments` | `user_id`, `course_id`, `enrolled_at` | UNIQUE `(user_id, course_id)` |
| `subscription_period_engagement` | `subscription_id`, `period_start`, `instructor_id`, `units` (minutes) | UNIQUE `(subscription_id, period_start, instructor_id)`; INDEX `instructor_id` |

Instructors are **not** users — there is no instructor portal (PLAN §20). The engagement table
references `subscriptions`, so its migration is timestamped after F04's.

### Seeded plans (illustrative prices, chosen to produce non-trivial rounding)

| Key | Months | Price |
|---|---|---|
| monthly | 1 | EGP 300.00 (30 000) |
| quarterly | 3 | EGP 800.00 (80 000) |
| annual | 12 | EGP 3 000.00 (300 000) |

## Seeders

**`PlanSeeder`** — idempotent (`updateOrCreate` by `key`).

**`DemoSeeder`** — small and **deterministic** (fixed Faker seed). ~5 instructors, ~12 courses,
~20 students, an admin user, and a curated set of scenario subscriptions for the video:

| Scenario | Setup | Demonstrates |
|---|---|---|
| S1 | annual, engagement 3/3/3 across three instructors | rounding (D‑5) |
| S2 | monthly, zero engagement | D‑3 |
| S3 | annual, started ~5 months before the seed date — ready to refund mid-term | F09 |
| S4 | quarterly, one instructor | whole pool to one instructor |
| S5 | an instructor whose balance stays below the payout minimum | carry-forward |

Subscriptions are created through F04's `SubscribeStudent` action — **never raw inserts** — so
the ledger is born consistent. The seeder ends by running `ledger:verify`.

**`ScaleSeeder`** *(Day 6)* — ~50k subscriptions, ~1M engagement rows.
Chunked bulk inserts of 1 000 rows, never per-row factories (minutes vs hours). Still writes
payments, ledger entries and accrual schedules through a batch variant of F04's action, then
runs `ledger:verify`. Prints timings for the video.

### Engagement generation

Per subscription per period: pick 1–6 instructors **among the instructors of courses the student
is enrolled in**, weights 1–600 minutes; ~10% of periods have zero engagement. Tying engagement
to enrolments keeps the seeded data coherent: nobody watches a course they aren't enrolled in.

## Factories

`Instructor`, `Course`, `Plan`, `User` (student / admin states), `Enrolment`, `Engagement`.

**Rule:** a `Subscription` factory may exist for isolated unit tests, but **any test that asserts
on balances creates subscriptions through the action.** A factory-made subscription has no
ledger entries and will fail `ledger:verify` — which is the correct behaviour.

## Rules

- Engagement is read **at recognition time**. Rows written for a period after it has been
  recognized are ignored (late-data policy — documented limitation).
- Engagement for a suspended instructor still counts; they earned it. Withholding payouts from
  suspended instructors is out of scope.

## Acceptance criteria

- [ ] `migrate:fresh --seed` succeeds from clean on MySQL
- [ ] `DemoSeeder` run twice from fresh → identical balances
- [ ] `ledger:verify` green after `DemoSeeder`
- [ ] `PlanSeeder` idempotent

## Tests

- `DemoSeeder` smoke test: runs, `ledger:verify` passes
- Factories produce rows satisfying every unique constraint
