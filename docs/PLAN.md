# Instructor Revenue Ledger — Analysis & Implementation Plan

**Challenge:** Career 180 — Full Stack Laravel Engineer Hiring Quest
**Stack:** Laravel 11 · Livewire 3 · Filament 3 · Pest 4 · MySQL 8 · Redis
**Status:** Planning document. No code written yet.
**Time budget:** ~1 week.

---

## 0. How to read this document

This is not a task list. It is the reasoning that the submission is actually graded on,
written down *before* the code exists so the code can be a faithful implementation of it.

Sections 2–4 are the thinking. Sections 5–13 are the design. Sections 14–17 are execution.

Every decision that the brief deliberately left open is recorded as a numbered **Decision (D‑n)**
with the alternatives that were rejected and why. These map 1:1 onto sections of
`docs/ARCHITECTURE.md` and onto talking points in the video.

---

## 1. Reading the evaluation criteria as a scoping instruction

The weights tell you what to build and — more importantly — what *not* to build.

| Area | Weight | What this means in practice |
|---|---|---|
| Correctness of money flows | 25% | Integer minor units, an append-only ledger, a provable "sum of parts = whole" invariant |
| Failure handling & idempotency | 20% | DB-enforced uniqueness, reserve-before-send, an explicit `unknown` state, reconciliation |
| System design & architecture | 20% | Accrual model, allocation strategy, clear layering, documented trade-offs |
| Laravel implementation & data integrity | 15% | Constraints, transactions, Actions/Services, batched jobs, correct transaction boundaries |
| Testing strategy & coverage | 10% | The three required proofs + invariant tests + rounding property tests |
| Documentation & engineering reasoning | 5% | README, ARCHITECTURE.md, AI_USAGE.md |
| Video & AI transparency | 5% | 15–20 min, six failure demos |

**65% of the grade is money correctness, failure handling, and design.** Zero percent is
features. The explicit statement is *"A smaller solution with strong engineering judgment will
score higher than a larger solution with weak reasoning."*

**Scoping consequence.** Everything built must serve the money core: build no LMS. No student-facing
UI, no auth screens, no enrolment flow, no checkout. Courses, enrolments and engagement exist only as
seeded data that feeds the allocator; payments and refunds are recorded as captured facts, because
the brief's story starts *after* the student has paid.

Two caveats on how to read that, since it is the one place this plan editorialises on top of the
brief:

- *Stated by the brief:* "you do not need a full application"; "build the core"; a six-item
  Required list whose only UI is one read-only screen; "more interested in [judgment] than in the
  number of features implemented"; and an evaluation table with **no row** for UI or feature
  completeness.
- *Inferred by me:* which specific things to therefore skip. The brief never enumerates them.

A thin student flow (auth, enrolment, checkout) was considered and withdrawn — see §20.

---

## 2. The problem, restated precisely

A student pays for a whole term up front (1, 3 or 12 months). That single payment must be
converted, over time, into amounts owed to many different instructors, minus the platform's cut.
The platform then pushes those amounts to an unreliable external provider on a schedule.

The system must answer at any instant, for any instructor:

1. **Owed** — lifetime earnings credited
2. **Paid** — successfully settled
3. **Outstanding** — owed − paid, split into *available now*, *held*, and *in flight*

And it must remain correct when: the payout runs twice, two servers run it simultaneously, a
worker is SIGKILL'd mid-transfer, the provider times out after having already moved the money,
and a student refunds halfway through an annual term.

### The one sentence the whole design hangs on

> *"Getting it wrong means an instructor is paid twice, paid the wrong amount, or paid money
> that later has to be recovered."*

Three distinct failure classes, and each gets its own structural answer:

| Failure | Structural answer | Section |
|---|---|---|
| Paid twice | Database unique constraints + reserve-before-send + provider idempotency keys | §9 |
| Paid the wrong amount | Integer minor units + largest-remainder allocation + zero-sum ledger invariant | §6, §7 |
| Paid money that must be recovered | Accrual (earn over time, not at payment) + hold period + clawback to negative balance | §4, §11 |

The third is the one most submissions will miss. It is not a failure-handling problem — it is a
*revenue recognition* problem, solved in the data model, before any job ever runs.

---

## 3. The questions the brief deliberately did not answer

The brief names three:

1. When does money count as **earned**?
2. How is a single payment **divided** between instructors?
3. What happens at the **messy edges**?

To which the real system adds:

4. When does earned money become **payable**?
5. Who absorbs **rounding loss**?
6. What happens when a clawback exceeds an instructor's balance?
7. What if a subscriber consumed **nothing** in a period?
8. Is a payout that we cannot confirm a success or a failure?

Each is answered below. Spotting them is part of the grade, so they are surfaced explicitly
in `ARCHITECTURE.md` rather than buried in code.

---

## 4. Decision log

### D‑1 — Money is earned by accrual over the term, never at payment time

**Decision.** A payment is recorded as a liability (*deferred revenue*), not as revenue. The term
is cut into **monthly accrual periods anchored to the subscription's start day**, with the final
period prorated by whole days. At the close of each period, that period's slice is *recognized*:
split into the platform's cut and instructor earnings.

- Monthly plan → 1 period. 3‑month → 3. Annual → 12.
- Per-period gross is the term price prorated **by days**, allocated across periods with the
  largest-remainder method (§6) so the twelve periods sum to the annual price exactly.

**Why.** This is the decision that makes the refund requirement tractable. If revenue were
recognized on day one, a student refunding in month two of an annual plan would force a clawback
of eleven months of instructor earnings — money that may already have left the building. Under
accrual, those eleven months were **never earned**, so there is nothing to claw back. The refund
touches only the deferred-revenue liability. Mid-term refunds stop being a financial emergency
and become a bookkeeping entry.

**Why monthly and not daily.** Daily periods at 500k subscriptions is 182M period rows a year
before instructor fan-out — hundreds of millions of allocation rows. Monthly gives 6M period rows
a year and, at ~5 instructors consumed per subscriber-month, ~30M allocation rows — which is
precisely the "tens of millions of underlying records" the brief describes. Monthly is also how
students perceive value delivery. Daily buys precision nobody uses, at 30× the storage.

**Rejected.** (a) *Recognize on payment* — simple, but every mid-term refund becomes a clawback
against possibly-already-paid money; it converts a routine event into the worst case. (b) *Daily
accrual* — 30× the row count for no business benefit. (c) *Recognize on consumption events* —
unbounded, non-deterministic, impossible to close a period.

---

### D‑2 — Allocation is engagement-weighted within the period

**Decision.** Within one accrual period, one subscription's instructor pool is divided among
instructors in proportion to **engagement units** (consumption minutes) that subscription
generated against each instructor's courses during that period.

Engagement is read from a pre-aggregated rollup table, not from raw view events. In production a
nightly job would fold raw events into `subscription_period_engagement`; here it is seeded. The
allocator reads one small aggregate row set per subscription-period.

**Why.** It is how consumption-based subscription platforms actually settle (Spotify, Audible,
Kindle Unlimited), it makes the "tens of millions of records" in the brief *explicable* rather
than arbitrary, and it is the only split that doesn't pay instructors for content nobody opened.
It also makes rounding genuinely hard — weights are arbitrary integers, not equal — which gives
the rounding demo real teeth instead of `100 / 3`.

**Rejected.** (a) *Equal split among enrolled instructors* — trivial, but a dormant course farms
revenue forever and the rounding case is degenerate. (b) *Weight by course list price* — the
student bought a subscription, not courses; list prices are marketing artifacts. (c) *Split by
enrolment count* — enrolment is a click, not value delivered.

---

### D‑3 — Zero engagement in a period → the platform retains that period's instructor pool

**Decision.** If a subscription generated no engagement in a period, no instructor is credited
for it; the whole period's gross is recognized as platform revenue.

**Why.** The weight denominator is zero — there is no defensible proportion. Instructors earn
when their work is consumed; a paying-but-dormant subscriber has consumed nothing from anyone.
Any alternative invents a beneficiary out of nothing.

**Close call — alternative noted in docs.** Splitting equally among instructors whose courses the
student is *enrolled* in is also defensible and is friendlier to instructors. It is rejected here
for consistency with D‑2 (earn on consumption, not on enrolment), but it is a one-line policy
swap and is written up as such. This is exactly the kind of call the reviewers want argued, not
hidden.

---

### D‑4 — Money is stored as integer minor units; the platform absorbs rounding loss

**Decision.** All amounts are `BIGINT` piastres (EGP ×100) with an explicit `currency` column.
No floats anywhere, ever. A small `Money` value object wraps (amount, currency) and refuses
cross-currency arithmetic.

Order of operations for one subscription-period:

```
pool     = intdiv(gross * instructor_share_bps, 10_000)   // floor
platform = gross - pool                                    // platform absorbs the sub-unit
shares   = largestRemainder(pool, weights)                 // sum(shares) === pool, exactly
```

**Why.** Floats lose money non-deterministically. `DECIMAL` is safe in MySQL but becomes a float
the moment PHP touches it. Integers are exact everywhere and compare exactly in tests. Flooring
the pool means the platform, not an instructor, eats the fractional piastre — the platform can
account for a rounding bucket; an instructor cannot be short-changed by an invisible remainder.

**The invariant, asserted in tests:** `platform + Σ shares === gross`, for every period, always.

---

### D‑5 — Remainders are distributed by the largest-remainder method with a deterministic tie-break

**Decision.** Integer division gives each instructor `floor(pool × wᵢ / W)`. The leftover
`pool − Σ floor(...)` piastres are handed out one each, in order of descending fractional
remainder, tie-broken by ascending `instructor_id`.

**Why.** Guarantees three things at once: the parts sum to the whole exactly; no instructor is
systematically favoured; and the result is **reproducible** — re-running the allocator on the
same inputs produces byte-identical output, which is what makes allocation idempotent (§9) and
testable.

**Rejected.** (a) *Round-half-up per share* — the parts don't sum to the whole; you invent or
destroy piastres. (b) *Give all remainder to the largest shareholder* — biased, and unfair at
scale over millions of periods. (c) *Drop the remainder* — the ledger stops balancing.

**Worked example** (pool = 1000, weights 3 / 3 / 3): floors 333/333/333, leftover 1, equal
remainders, tie-break by id → instructor 1 gets 334. Sum = 1000. Deterministic on every re-run.

---

### D‑6 — Earned money becomes payable only after a hold period

**Decision.** An earning credited for period *P* has `available_at = P.period_end + 7 days`.
The payout run only considers balance that has matured.

**Why.** Refunds cluster immediately after a period closes. The hold lets the most likely
clawbacks land *before* the money is irreversible, converting "recover money from an instructor"
into "reduce a pending balance" — which is free. It is a direct answer to the third failure class
in §2.

**Trade-off, stated openly:** instructors wait an extra week. Seven days is a tunable config
value, not a hardcoded constant — the correct number is a business decision about refund-window
length versus instructor cashflow, and the code should make it obvious that it is a dial.

---

### D‑7 — A clawback that exceeds an instructor's balance carries forward as a negative balance

**Decision.** A refund debits the instructor's payable account. If the balance goes negative, it
stays negative and is netted against future earnings. The platform never issues a collection
demand and never reverses a completed payout at the provider.

**Why.** Reversing a settled transfer is an operational nightmare and often legally awkward.
Carry-forward netting is standard practice (it is what Stripe Connect, YouTube and app stores do)
and it is self-healing for any instructor with ongoing earnings.

**Known limitation, documented:** an instructor who goes negative and then stops teaching leaves
an unrecoverable balance. Real systems cap this with the hold period, a reserve percentage, and
a write-off process after N days. Out of scope; named in `ARCHITECTURE.md`.

---

### D‑8 — A payout we cannot confirm is neither a success nor a failure

**Decision.** The provider's three outcomes map to **four** internal states. `TIMEOUT` maps to
`unknown`, a first-class terminal-pending state — never to `failed`.

| Provider outcome | Item status | Money | Next action |
|---|---|---|---|
| Success | `succeeded` | settled | none |
| Permanent failure | `failed` | returned to available | operator review / next run |
| Timeout (may have succeeded) | `unknown` | **stays reserved** | reconciliation sweep |
| No response / worker died | `submitted` | **stays reserved** | reconciliation sweep |

**Why.** Treating a timeout as a failure is *the* classic double-payment bug: the money moved,
the app thinks it didn't, the next run pays again. Treating it as a success is the classic
silent-loss bug. The only honest answer is "we don't know yet" — modelled explicitly, with the
money frozen in place until the provider tells us the truth via a status check.

This is the single most important design decision in the submission and gets its own video segment.

---

### D‑9 — The ledger is append-only and double-entry; balances are a maintained snapshot

**Decision.** `ledger_entries` is append-only — no `UPDATE`, no `DELETE`, ever. Corrections are
new, opposite entries. Every business event writes a **group of entries sharing a
`transaction_uuid` whose signed amounts sum to zero** across accounts.

Accounts: `platform_cash`, `deferred_revenue`, `platform_revenue`, `instructor_payable[id]`,
`provider_in_transit`.

Because summing tens of millions of rows per page load is not viable, `instructor_balances` holds
a maintained snapshot (`earned`, `clawed_back`, `reserved`, `paid`, `available`,
`last_ledger_entry_id`). It is a **cache with a provable source of truth**: a
`ledger:verify` command recomputes from the ledger and asserts equality, and asserts the global
zero-sum invariant. This is demoed in the video.

**Why.** Auditability plus O(1) reads. A mutable balance column alone cannot answer "why is it
this number?", and a pure `SUM()` cannot answer it fast. The pairing gives both, and the
verifier turns "trust me" into "run this command".

**Rejected.** (a) *Balance column only* — no audit trail, race-prone, unexplainable. (b) *Pure
SUM at read time* — correct but unusable at the stated scale. (c) *Full chart of accounts with
journals* — right for a real ledger, over-engineered for a week; the zero-sum discipline captures
90% of the value at 10% of the cost, and that trade-off is itself a talking point.

---

### D‑10 — Idempotency is enforced by database constraints; locks are only an optimization

**Decision.** Every "must happen once" fact is protected by a `UNIQUE` index. Redis locks and
`ShouldBeUnique` are added on top to avoid wasted work, but **correctness never depends on them**.

**Why.** A lock can expire, a Redis node can fail over, a clock can skew, a process can be paused
by the OS past its TTL. A unique index in the same database as the data cannot be wrong. If the
only thing standing between the platform and a double payment is a distributed lock, the design
is wrong. This distinction — *constraints for correctness, locks for efficiency* — is the
sentence to say out loud in the review.

---

## 5. Domain model & schema

### 5.1 Supporting tables (seeded, not built out)

| Table | Purpose |
|---|---|
| `users` | students (Laravel default) + `is_admin` for Filament |
| `instructors` | `payout_account_ref`, `status`, optional `revenue_share_bps` override |
| `courses` | `instructor_id`, `title` |
| `enrolments` | `user_id`, `course_id` — **UNIQUE** pair. Seeded; engagement is generated only for courses a student is enrolled in |
| `plans` | `key`, `interval_months`, `price_minor`, `currency` |

### 5.2 Money-in

| Table | Key columns | Constraints |
|---|---|---|
| `subscriptions` | `user_id`, `plan_id`, `term_start`, `term_end`, `price_minor`, `status` | index `(status, term_end)` |
| `payments` | `subscription_id`, `amount_minor`, `external_ref`, `paid_at` | **UNIQUE** `external_ref` |
| `refunds` | `payment_id`, `amount_minor`, `effective_at`, `reason` | **UNIQUE** `external_ref` |

### 5.3 Recognition

| Table | Key columns | Constraints |
|---|---|---|
| `accrual_periods` | `subscription_id`, `period_start`, `period_end`, `days`, `gross_minor`, `pool_minor`, `platform_minor`, `status`, `recognized_at` | **UNIQUE** `(subscription_id, period_start)` |
| `subscription_period_engagement` | `subscription_id`, `period_start`, `instructor_id`, `units` | **UNIQUE** `(subscription_id, period_start, instructor_id)` |
| `earning_allocations` | `accrual_period_id`, `instructor_id`, `weight_units`, `amount_minor`, `available_at` | **UNIQUE** `(accrual_period_id, instructor_id)` |

`accrual_periods` is written once when the subscription is created (the full schedule is known
from day one, since the term is paid up front). Recognition flips `status` from `scheduled` to
`recognized` and is guarded by a conditional update — see §9.

### 5.4 Ledger & balances

| Table | Key columns | Notes |
|---|---|---|
| `ledger_entries` | `transaction_uuid`, `account_type`, `account_id`, `amount_minor` (signed), `currency`, `entry_type`, `reference_type`, `reference_id`, `available_at`, `payout_item_id`, `created_at` | append-only; **UNIQUE** `(entry_type, reference_type, reference_id, account_type, account_id)`; index `(account_type, account_id, id)` |
| `instructor_balances` | `instructor_id` PK, `earned_minor`, `clawed_back_minor`, `reserved_minor`, `paid_minor`, `available_minor`, `last_ledger_entry_id` | snapshot; rebuildable from ledger |

That unique index on `ledger_entries` is the workhorse: it makes *writing a ledger entry* itself
idempotent. Replaying any event — a retried job, a re-run command, a duplicated webhook — hits
the constraint and is swallowed by `insertOrIgnore`. Nothing downstream has to be careful.

### 5.5 Payouts

| Table | Key columns | Constraints |
|---|---|---|
| `payout_runs` | `run_key`, `scheduled_for`, `status`, `totals`, `started_at`, `finished_at` | **UNIQUE** `run_key` |
| `payout_items` | `payout_run_id`, `instructor_id`, `amount_minor`, `status`, `idempotency_key`, `provider_reference`, `attempts`, `next_check_at`, `submitted_at`, `settled_at`, `last_error` | **UNIQUE** `(payout_run_id, instructor_id)`, **UNIQUE** `idempotency_key`; index `(status, next_check_at)` |
| `payout_attempts` | `payout_item_id`, `attempt_no`, `request`, `response`, `outcome`, `created_at` | append-only audit of every provider interaction |

`payout_attempts` exists so the video can show, on screen, that a timeout and its later
confirmation were two interactions with **one** transfer — not two transfers.

### 5.6 The entry patterns (every one sums to zero)

```
Payment received      DR platform_cash            CR deferred_revenue
Period recognized     DR deferred_revenue         CR platform_revenue
                                                  CR instructor_payable[i]   (one per instructor)
Payout reserved       DR instructor_payable[i]    CR provider_in_transit
Payout succeeded      DR provider_in_transit      CR platform_cash
Payout failed         DR provider_in_transit      CR instructor_payable[i]   (reversal)
Refund — unearned     DR deferred_revenue         CR platform_cash
Refund — earned       DR platform_revenue
                      DR instructor_payable[i]    CR platform_cash           (clawback)
```

---

## 6. Money handling

- `App\Support\Money` — immutable `(int $minor, string $currency)`; arithmetic guards currency.
- `App\Support\Allocator::largestRemainder(int $total, array $weights): array` — pure, static,
  no DB, no framework. Trivially unit-testable; this is where property-based tests live.
- One config file, `config/revenue.php`: `platform_share_bps`, `hold_days`,
  `minimum_payout_minor`, `zero_engagement_policy`. Every policy decision above is a visible dial,
  not a magic number buried in a service.
- `minimum_payout_minor` (e.g. 10000 = EGP 100): below-threshold balances are skipped and carry
  forward, so the platform isn't paying provider fees to move EGP 3.

---

## 7. Revenue allocation pipeline

```
subscription created
   └─ AccrualScheduler  → writes N accrual_periods (status=scheduled)
                           gross split across periods by days, largest-remainder

ledger:accrue --date=  (daily, scheduled)
   └─ for each period where period_end <= date and status = scheduled
        ├─ conditional UPDATE status scheduled → recognizing  (CAS; affected=1 or skip)
        ├─ read engagement rollup for (subscription, period)
        ├─ pool = floor(gross × share_bps / 10_000); platform = gross − pool
        ├─ shares = largestRemainder(pool, weights)          [D-5]
        ├─ TRANSACTION:
        │     insertOrIgnore earning_allocations
        │     insertOrIgnore ledger_entries (one txn group, sums to zero)
        │     increment instructor_balances
        │     UPDATE period status → recognized
        └─ COMMIT
```

Processed in keyset-paginated chunks (`WHERE id > ?`, never `OFFSET`), in batched jobs. The
conditional-update CAS plus the two `insertOrIgnore` unique constraints mean the whole stage is
safe to run twice, concurrently, or after a crash.

---

## 8. Payout architecture

### 8.1 State machine

```
pending ──reserve──▶ reserved ──submit──▶ submitted ──▶ succeeded
                        ▲                     │
                        │                     ├──▶ failed  (money returned to available)
                        │                     │
                        └──── release ────────┴──▶ unknown ──reconcile──▶ succeeded | failed
                                                     │
                                                     └──(max attempts)──▶ needs_review
```

Every transition is a conditional `UPDATE ... WHERE status = <expected>` with an
`affected_rows === 1` assertion. That is a compare-and-swap, enforced by the database, costing
nothing, and it makes every transition idempotent on its own.

### 8.2 The ordering rule

> **Commit the intent, then call the provider, then commit the outcome.**
> Never hold a database transaction open across a network call.

If the worker dies at any point after the intent is committed, the `payout_items` row and its
`idempotency_key` are already durable, so the reconciliation sweep can discover what really
happened. A transaction held across the HTTP call would either roll back a real transfer out of
the records, or pin a row lock for the provider's entire timeout — both fatal at scale.

### 8.3 Commands

| Command | Purpose |
|---|---|
| `payouts:run {--run-key=} {--dry-run} {--min-amount=}` | create/find run, reserve balances, dispatch jobs |
| `payouts:reconcile` | sweep `submitted` / `unknown` items past `next_check_at`, query provider status |
| `ledger:accrue {--date=}` | recognize matured periods |
| `ledger:verify` | recompute balances from ledger; assert snapshot equality and global zero-sum |

`--run-key` defaults to a deterministic value (`payout:2026-09`). Re-running the command for the
same period finds the existing run by its unique key instead of creating a second one. `--dry-run`
prints what would be paid without reserving — useful for the video.

### 8.4 Jobs

- `ProcessPayoutItemJob` — `ShouldBeUnique` on item id, `tries = 5`, exponential backoff with
  jitter, `failed()` moves the item to `needs_review` (never to `pending`).
- `ReconcilePayoutItemJob` — backoff 1m → 5m → 30m → 2h → 6h, capped via `retryUntil()` at 24h,
  then `needs_review`.
- Dispatched with `afterCommit()` so a job can never observe uncommitted state.
- Grouped in `Bus::batch()` per run for progress and a `finally()` hook that closes the run.
- Dedicated `payouts` Redis queue with limited concurrency to respect provider rate limits.

### 8.5 Mock provider

```php
interface PaymentProvider {
    public function transfer(TransferRequest $r): TransferResult;   // idempotency_key on the request
    public function getStatus(string $idempotencyKey): TransferResult;
}
```

`RandomMockProvider` — succeeds / fails permanently / times out *after* recording an internal
success. Its internal store is keyed by `idempotency_key`, so a retry with the same key returns
the **original** result rather than transferring again. That is the whole point: it models a real
provider's server-side dedup, which is what turns our at-least-once delivery into
effectively-exactly-once.

`ScriptedMockProvider` — deterministic, sequence-driven, used by tests.

---

## 9. Idempotency: layer by layer

| # | Risk | Mechanism | Enforced by |
|---|---|---|---|
| 1 | Duplicate payment ingestion | `UNIQUE payments.external_ref` | DB |
| 2 | Period recognized twice | `UNIQUE (subscription_id, period_start)` + status CAS | DB |
| 3 | Instructor credited twice for a period | `UNIQUE (accrual_period_id, instructor_id)` | DB |
| 4 | Duplicate ledger entry | `UNIQUE (entry_type, reference_type, reference_id, account_type, account_id)` | DB |
| 5 | Two concurrent payout runs | `UNIQUE payout_runs.run_key` | DB |
| 6 | Two payouts to one instructor in a run | `UNIQUE (payout_run_id, instructor_id)` | DB |
| 7 | Re-run pays already-paid balance | reserve-before-send: balance leaves `available` atomically | DB txn |
| 8 | Retried job re-transfers | same `idempotency_key` → provider dedups | provider + DB |
| 9 | Invalid state transition | conditional `UPDATE ... WHERE status = ?`, assert `affected = 1` | DB |
| 10 | Wasted concurrent work | `Cache::lock` (Redis) + `ShouldBeUnique` + `WithoutOverlapping` | Redis *(optimization only)* |

Rows 1–9 are correctness. Row 10 is efficiency. **If Redis disappeared entirely, no instructor
would be paid twice.** That is the claim the test suite has to back up.

---

## 10. Failure matrix

| Scenario | System behaviour | Money outcome |
|---|---|---|
| Command run twice, same key | second finds existing run; reserved balance already zero | paid once |
| Two servers, same second | one wins the unique index; the other loads the existing run | paid once |
| Job retried after exception | same `idempotency_key`; provider returns original result | paid once |
| Worker SIGKILL'd after send, before response | item stuck in `submitted`, money reserved; reconcile resolves | paid once |
| Provider times out after succeeding | `unknown`; money stays reserved; reconcile finds success | paid once |
| Provider fails permanently | reversal entries; balance returns to `available` | paid next run |
| Provider never resolves | `needs_review` after 24h; money still reserved, never re-sent blindly | operator decides |
| Refund before period recognized | period cancelled; deferred revenue reversed | no instructor impact |
| Refund after recognition, within hold | clawback against pending balance | reduces next payout |
| Refund after payout | clawback → negative balance, netted forward | recovered over time |
| Pool doesn't divide evenly | largest remainder; platform absorbs the top-level floor | sums exactly |

Those eleven rows are the video's demo script.

---

## 11. Refunds

A refund carries an `effective_at`. It partitions the term into three zones:

1. **Not yet recognized** — periods with `period_start >= effective_at`. Cancelled outright.
   Deferred revenue reversed against cash. **Zero instructor impact.** This is the accrual payoff.
2. **Recognized, not yet paid** — clawback entries reduce `available` / `pending`. Free to the
   platform; the hold period (D‑6) is engineered to make this the common case.
3. **Recognized and paid** — clawback entries push the balance negative; netted against future
   earnings (D‑7).

Refund amount for a partial mid-term refund = `price × remaining_days / term_days`, computed in
whole days with the same largest-remainder discipline so partial refunds can never sum to more
than the original payment.

---

## 12. Scale: 500k subscriptions, tens of millions of rows

| Concern | Approach |
|---|---|
| Iteration | keyset pagination (`WHERE id > ?`), `lazyById()`, never `OFFSET`, never `->get()` on a large set |
| Writes | chunked bulk `insertOrIgnore` (1k rows/statement), not per-row Eloquent saves |
| Balance reads | snapshot table, O(1); never `SUM()` the ledger at request time |
| Fan-out | `Bus::batch()` over instructor chunks; one job per payout item |
| Indexes | `ledger_entries (account_type, account_id, id)`; `payout_items (status, next_check_at)`; the uniques in §5 |
| Hot rows | `instructor_balances` updated with atomic `UPDATE ... SET x = x + ?`, never read-modify-write in PHP |
| Growth | `ledger_entries` partitioned by month — *documented as future work, not implemented* |
| Archival | closed runs + settled items cold-storable after N months — *documented* |

A seeder generating a realistic slice (e.g. 50k subscriptions, ~1M engagement rows) lets the
video show a payout run against non-trivial data with timings, instead of asserting scalability.

---

## 13. Filament screen

Read-only, `canCreate/canEdit/canDelete = false`.

**`InstructorResource` — list:** name · available · pending (held) · reserved (in flight) ·
lifetime earned · lifetime paid · outstanding · last payout.

**View page, two relation managers:**
- *Payout history* — run key, amount, status badge (including `unknown` / `needs_review`),
  provider reference, submitted/settled timestamps.
- *Recent ledger entries* — the audit trail behind the number above it.

**`PayoutRunResource`** — runs with item counts by status. Cheap to add, and it is what makes the
failure demos legible on camera: the reviewer watches an item sit in `unknown`, then flip to
`succeeded` after `payouts:reconcile` runs.

---

## 14. Testing plan (Pest)

**Run against real MySQL, not SQLite.** The design leans on unique-constraint violations,
`SELECT ... FOR UPDATE`, and transaction semantics. SQLite would quietly change the behaviour
being proven. Note this in the README — it is itself a signal of understanding.

### The three required proofs

| Test | Method | Assertion |
|---|---|---|
| Payout run twice never double-pays | run `payouts:run` twice with the same key | one `payout_run`, one item/instructor, provider `transfer()` called once, `paid_minor` incremented once |
| Retried jobs never double-pay | dispatch `ProcessPayoutItemJob` twice; also handle → throw → handle | one `succeeded` item, two `payout_attempts` rows, **one** provider transfer |
| Unreliable provider never duplicates | scripted timeout-after-success, then `payouts:reconcile` | item `unknown` → `succeeded`; provider's internal transfer count === 1 |

### Core business logic (unit, no DB)

- `largestRemainder`: sum-equals-total for hand-picked cases **and** as a property test over
  hundreds of random (total, weights) pairs — the strongest possible statement of D‑5.
- Degenerate inputs: single instructor; all-zero weights; total of 1 piastre; total of 0.
- Determinism: same input → identical output across repeated calls.
- Term-to-period proration: 12 periods of an annual price sum to the price exactly, including
  a leap-year February.
- Platform cut: `platform + Σ shares === gross` across a fuzzed range of share_bps values.

### Integration

- Accrual is idempotent: run `ledger:accrue` three times → one set of allocations.
- Concurrency: two processes attempt the same run key → exactly one creates it.
- Refund in each of the three zones (§11) → expected balance deltas; unearned zone leaves
  instructor balances *untouched*.
- Hold period: an earning is invisible to `payouts:run` before `available_at`, visible after.
- Minimum threshold: a below-threshold balance is skipped and carried to the next run.
- **Chaos/invariant test** — the headline test: simulate N randomized events (payments, accruals,
  refunds, payout runs with a randomly failing provider, duplicate command invocations,
  re-dispatched jobs), then assert:
  1. `Σ ledger_entries.amount_minor === 0` globally,
  2. every snapshot balance equals its recomputed ledger sum,
  3. every `succeeded` item has exactly one provider transfer,
  4. `Σ paid ≤ Σ earned` for every instructor with no clawbacks.

  This is one test that cannot pass by accident, and it is the one to show on camera.

---

## 15. Deliverables checklist

**Code**
- [ ] Migrations for every table in §5, with the constraints in §9
- [ ] Factories: instructor, course, plan, enrolment, subscription, payment, engagement, accrual period
- [ ] Seeders: `DemoSeeder` (small, for the video) and `ScaleSeeder` (large, for timings)
- [ ] `Money`, `Allocator`, `config/revenue.php`
- [ ] Actions: `RecognizeAccrualPeriod`, `AllocatePeriodRevenue`, `ReserveInstructorBalance`,
      `SubmitPayoutItem`, `SettlePayoutItem`, `ApplyRefund`
- [ ] Commands: `payouts:run`, `payouts:reconcile`, `ledger:accrue`, `ledger:verify`
- [ ] Jobs: `AccrueSubscriptionPeriodsJob`, `ProcessPayoutItemJob`, `ReconcilePayoutItemJob`
- [ ] `PaymentProvider` interface + `RandomMockProvider` + `ScriptedMockProvider`
- [ ] Filament: `InstructorResource`, `PayoutRunResource` (read-only)

**Docs**
- [ ] `README.md` — setup, `php artisan test`, assumptions, why MySQL not SQLite
- [ ] `docs/ARCHITECTURE.md` — decisions D‑1…D‑10, allocation strategy, idempotency layers,
      timeout handling, scaling, known limitations
- [ ] `docs/AI_USAGE.md` — see §18

**Evidence**
- [ ] Screenshot of the passing suite
- [ ] 15–20 min video (§17)

---

## 16. One-week schedule

| Day | Focus | Done when |
|---|---|---|
| 1 | Schema + migrations + factories + seeders; `Money` + `Allocator` with full unit tests | `largestRemainder` property tests green |
| 2 | Accrual: period scheduling, recognition, ledger entries, balance snapshot; `ledger:verify` | accrue runs 3× → identical state |
| 3 | Payout: runs, items, reserve-before-send, state machine, commands, jobs | `payouts:run` pays a seeded instructor once |
| 4 | Provider mocks, timeout/`unknown` handling, `payouts:reconcile`, `payout_attempts` | the three required proofs pass |
| 5 | Refunds (three zones), clawbacks, hold period, minimum threshold; chaos/invariant test | invariant test green over randomized runs |
| 6 | Filament screens; `ScaleSeeder` + timing pass; `ARCHITECTURE.md`; `README.md` | reviewer could clone and run it |
| 7 | `AI_USAGE.md`, rehearse and record the video, screenshots, final polish | submitted |

Days 1–5 are the 65% of the grade. If time runs short, the Filament screen shrinks to one
resource and the scale seeder is dropped — **never** the tests or the docs.

---

## 17. Video plan (15–20 min)

| Segment | Time | Content |
|---|---|---|
| Intro | 2–3 | Background; prior payments/ledger/event-driven work; why accrual |
| Architecture | 5 | Domain model → schema → D‑1, D‑2, D‑4, D‑5, D‑9 → payout state machine |
| Failure demos | 5–7 | See below — live terminal + Filament side by side |
| Testing | 2–3 | The three proofs; why the invariant test cannot pass by accident |
| AI usage | 2–3 | What was generated, what was rejected, decisions owned personally |
| Future | 1–2 | Partitioning, reserves, real provider webhooks, multi-currency, remaining risks |

**Demo order** (each ends by showing `ledger:verify` still green):

1. `payouts:run` twice → one payment. Show the unique index, not the lock.
2. Two `payouts:run` processes simultaneously → one run created.
3. Kill a worker mid-transfer → item stuck `submitted` → `payouts:reconcile` resolves it.
4. Scripted timeout-after-success → `unknown` in Filament → reconcile → `succeeded`,
   `payout_attempts` shows two interactions, provider shows one transfer.
5. Refund mid-annual-term → show that unearned months were never allocated; clawback on the rest.
6. Rounding: pool of 1000 across weights 3/3/3 → 334/333/333, sum exact.

**The line to land:** *"Correctness lives in the database constraints. The locks are only there
to stop us wasting work."*

---

## 18. AI usage discipline

The brief allows AI and requires disclosure — and warns that the review will ask you to explain
and *modify the implementation live*. So the workflow matters as much as the output.

Practical implications while building:

- Keep a running decision log as you go (a scratch file), noting what you accepted from AI, what
  you rejected and why. Writing `AI_USAGE.md` honestly on day 7 from memory is much harder than
  appending to it daily.
- The decisions D‑1 … D‑10 are the ones you must be able to defend cold, without notes, and
  argue the rejected alternatives for. If any of them doesn't feel like *yours*, change it to
  one that does — a defended "wrong" call scores better than an undefended right one.
- Be specific in the disclosure: "schema drafted with AI then reworked to add the hold period
  and the reserve state" is credible; "used AI for boilerplate" is not.
- The section that differentiates you is *what you rejected*. A typical AI-generated submission
  recognizes revenue at payment time, stores a `balance` column, uses a Redis lock as its only
  idempotency guarantee, and treats a timeout as a failure. Naming those four and explaining why
  this design does the opposite is the strongest three minutes in the video.

---

## 19. Senior bonus — plan change mid-term (discussion only, do not build)

Accrual makes this almost mechanical, which is the point worth making.

A student on an annual plan upgrades in month 5:

1. **Close the current period early.** Prorate the in-flight period to the switch date by whole
   days and recognize it normally. Everything already earned stays earned; no instructor is
   affected by a decision they had no part in. *This is the whole argument for D‑1.*
2. **Compute the unearned remainder.** Future `scheduled` periods are cancelled; their gross is
   the student's credit. It is sitting in deferred revenue already — no cash movement needed to
   find it.
3. **Apply the credit to the new plan.** Charge `new_price − credit`. If negative (a downgrade),
   issue **account credit**, not cash — credit keeps the money in deferred revenue where it can
   still be earned by instructors, and avoids provider refund fees and fraud surface.
4. **Write a new accrual schedule** for the new term from the switch date, with the same
   largest-remainder proration so periods sum to the new price exactly.
5. **Ledger:** one zero-sum transaction group — debit old deferred revenue, credit new deferred
   revenue, and debit/credit cash for the difference. Auditable end to end, no balance rewritten.

Open questions to raise rather than answer in the video: should an upgrade's unused *days* or
unused *value* carry (they differ when plan-level discounts vary)? Should downgrades take effect
immediately or at the next period boundary (immediate is fairer to the student, boundary is far
simpler and avoids gaming)? Should instructor allocations for the shortened period be weighted by
the partial period's engagement or the full month's? Naming these is the answer they want.

---

## 20. Explicitly out of scope — and why

| Not built | Reason |
|---|---|
| Student auth, registration, enrolment UI, checkout | Zero weight; the brief's story starts *after* the student has paid. A thin Livewire slice was considered and withdrawn — the trade-off is that Livewire skill shows only through Filament |
| Instructor portal and dashboards | Zero weight; the Filament screen covers the read case |
| Real payment intake, card handling, a live gateway | Payments and refunds are recorded as captured facts, each keyed by a unique external reference |
| Course/lesson CRUD, video, progress tracking | Seeded data is sufficient input to the allocator |
| Raw engagement event ingestion | Rollup table is the interface; ingestion is a separate system |
| Multi-currency / FX | Single currency, `currency` column present for forward compatibility |
| Tax, VAT, withholding, 1099-equivalents | Real requirement, large, orthogonal — named as a limitation |
| Instructor KYC / payout account onboarding | Provider concern |
| Table partitioning, archival | Designed for and documented; not implemented in a week |
| Negative-balance write-off policy | Named as a known limitation (D‑7) |

Each of these appears in `ARCHITECTURE.md` under *Known Limitations*. Naming what you chose not
to build, with a reason, reads as judgment. Silence reads as an oversight.

---

## 21. Calls made on your behalf — overrule any of these

These are defensible either way; they are set so the plan is actionable, not because the
alternative is wrong:

1. **Engagement-weighted allocation (D‑2)** is the most ambitious choice here. Equal-split among
   enrolled instructors is ~half a day cheaper and still defensible. If the week gets tight, this
   is the one thing worth downgrading — the rest of the design is unaffected.
2. **Zero engagement → platform retains (D‑3)** is a genuine coin-flip; equal split among enrolled
   instructors is equally arguable.
3. **Monthly accrual periods (D‑1)**. Daily is more precise and much heavier. If you would rather
   demo daily accrual, the scale numbers in §12 need reworking.
4. **7-day hold (D‑6)** is an arbitrary starting value — config, not doctrine.
5. **Double-entry-lite (D‑9)** rather than a full chart of accounts. If you are comfortable
   defending a full journal/account structure, it scores higher on design — at real cost in time.

---

**Next step:** confirm or overrule §21, then Day 1 of §16.
