# Architecture

The system in one sentence: **money enters as a liability, is earned over time, is split by
measured engagement, and every movement is an append-only double-entry posting that a single
command can recompute from scratch.**

Everything below follows from that. Where a decision is contestable it is named, the alternative
is stated, and the reason for the call is given — the numbered decisions `D-1 … D-10` were made in
[`PLAN.md`](PLAN.md) before any code, and the numbered refinements `R1 … R50` in
[`features/README.md`](features/README.md) record every place the build changed the plan.

---

## Contents

- [Key architectural decisions](#key-architectural-decisions)
- [The layering contract](#the-layering-contract)
- [The ledger](#the-ledger)
- [Revenue allocation strategy](#revenue-allocation-strategy)
- [Idempotency approach](#idempotency-approach)
- [The payout state machine](#the-payout-state-machine)
- [Provider timeout handling](#provider-timeout-handling)
- [Refunds](#refunds)
- [Invariants, and the command that checks them](#invariants-and-the-command-that-checks-them)
- [Scaling considerations](#scaling-considerations)
- [Known limitations](#known-limitations)
- [Discussion: a plan change mid-term](#discussion-a-plan-change-mid-term)

---

## Key architectural decisions

| # | Decision | The alternative, and why not |
|---|---|---|
| **D-1** | **Money is earned by accrual over the term, never at payment time.** A payment creates a `deferred_revenue` liability. A schedule of monthly periods is written at the same instant, summing to the price exactly. Revenue is recognized period by period as each window closes. | Recognizing at payment is one line of code and wrong in every direction that matters: a refund in month 5 of 12 would have to claw back money already paid to instructors, an instructor who taught in month 7 would be paid from month 1's engagement, and the platform's books would claim a year of income on day one. Every hard case in this system — refunds, upgrades, clawbacks — is hard *because* of this decision and is tractable *only* because of it. |
| **D-2** | **Allocation is engagement-weighted within the period.** A period's instructor pool is split by minutes watched per instructor, in that period, for that subscription. | Equal split among enrolled instructors is about half a day cheaper and defensible. It also pays an instructor nobody watched the same as one who carried the term. Engagement weighting is the harder claim and the one the brief is actually about. |
| **D-3** | **A period with no engagement at all: the platform retains that period's pool.** | Splitting equally among the enrolled is equally arguable, which is exactly why this is a config dial (`REVENUE_ZERO_ENGAGEMENT_POLICY`) and not a literal. There is no defensible *proportion* when the denominator is zero, so the honest options are "nobody" or "everybody", and the code refuses to guess for you. Only `platform_retains` is implemented; the other is documented, not built. |
| **D-4** | **Integer minor units end to end; the platform absorbs rounding loss.** Signed `BIGINT` piastres, no float, no `DECIMAL`-to-string maths, no `round()` on money anywhere. The instructor pool is *floored*, so the sub-unit falls to the platform. | Floats lose piastres silently, and a system whose whole claim is "the ledger balances" cannot afford a representation that makes that untrue at the eleventh decimal. Flooring in the platform's disfavour would be defensible too; flooring in the instructor's disfavour is what most systems do and is why the direction is stated rather than left to the reader. |
| **D-5** | **Largest-remainder distribution with a deterministic tie-break.** Each instructor gets `floor(pool × wᵢ / W)`; leftover piastres go one each by descending fractional remainder, tie-broken by ascending instructor id. | Rounding each share half-up does not sum to the whole — you invent or destroy piastres. Giving the remainder to the largest shareholder is biased over millions of periods. Dropping it breaks the ledger. The tie-break is not decoration: it is what makes re-running the allocator on the same inputs produce byte-identical output, which is what makes allocation idempotent and testable. |
| **D-6** | **Earned money is held before it becomes payable** (7 days, config). | Paying the instant revenue is recognized means a refund inside the window is a clawback against money that has already left the building. Inside the hold, the same refund is a bookkeeping entry. |
| **D-7** | **A clawback that exceeds a balance carries forward as a negative balance.** Never a collection demand, never a reversal of a settled transfer at the provider. | Reversing a completed transfer is an operational and often legal nightmare. Carry-forward netting is what Stripe Connect, YouTube and the app stores do, and it is self-healing for anyone still earning. Its failure mode is named under [Known limitations](#known-limitations). |
| **D-8** | **A payout we cannot confirm is neither a success nor a failure.** A timeout produces the status `unknown`, the money stays reserved, and a reconciliation sweep asks the provider again. | This is the decision that separates a payments system from a CRUD app. Treating a timeout as failure double-pays whenever the transfer actually landed; treating it as success under-pays whenever it did not. The third state costs a status value and a sweep, and it is the only honest model of the world. |
| **D-9** | **The ledger is append-only and double-entry; balances are a maintained snapshot.** Every movement is a transaction of legs summing to zero. `instructor_balances` is a cache of the ledger, never the source of truth, and `ledger:verify` recomputes it. | A mutable `balance` column is faster to write and impossible to audit: when it is wrong there is no way to find out when it went wrong or by how much. Here, the snapshot exists for read performance and the ledger exists for truth, and a single command proves they agree. |
| **D-10** | **Idempotency is enforced by database constraints; locks are only an optimization.** Unique indexes plus conditional `UPDATE ... WHERE status = ?` asserting `affected === 1`. `Cache::lock`, `ShouldBeUnique` and `WithoutOverlapping` save wasted work and guarantee nothing. | A Redis lock as the only guard is the single most common way systems like this double-pay: the lock expires mid-operation, or Redis fails over, and nothing in the database prevents the second write. The claim this system makes — **if Redis disappeared entirely, no instructor would be paid twice** — is checkable, and the suite checks it. |

`D-11` (a student-facing flow) was **withdrawn** during the build and is recorded as `R25`. The
brief's story starts after the student has paid, and the time went into the scale seeder, the
admin panel and the documentation instead. The stated cost: Livewire skill shows only through
Filament.

---

## The layering contract

```
Entry point      Artisan command · queued job · Filament page
     ↓           validates, authorizes, builds the input contract
   DTO           final readonly, scalars and enums only; reads the clock at most once
     ↓
 Action          one use case, invokable, owns the transaction boundary, never queries
     ↓
Service          the only layer that touches Eloquent or DB; opens no transactions
     ↓
 Model           relationships, casts, scopes
```

`App\Support` (`Money`, `Allocator`, `RevenueSplit`, `AccrualSchedule`, `RefundPlan`) sits beside
the chain: pure functions, no framework, no I/O, no clock, no config. That is what makes the money
maths property-testable without a database.

Every aggregate is reached through an interface in `App\Contracts`, bound to its implementation in
one table in `AppServiceProvider`. The services are `final`, which means an Action's collaborators
could not be doubled until the contracts existed — the seam was promised in the plan and only
became real in `R48`.

**This is enforced, not documented.** `tests/Feature/ArchTest.php` fails the build if an Action
queries, a DTO imports `Illuminate`, an entry point reaches past an Action into a Service, a
Service opens a transaction, `App\Support` reads config or the clock, or a class reaches a Service
by its concrete name instead of its contract. Guidance that only lives in a markdown file is
advice; this is a red test.

---

## The ledger

Double-entry, append-only. One business fact is one transaction, identified by a
`transaction_uuid`, made of legs that sum to zero. Nothing ever updates or deletes a row —
`ledger_entries` has no `updated_at` for anything to rewrite, and the immutability is asserted from
both the model side and by bypassing the model entirely.

### Entry patterns — every one sums to zero

| Business fact | Debit | Credit |
|---|---|---|
| Payment received | `platform_cash` | `deferred_revenue[sub]` |
| Period recognized | `deferred_revenue[sub]` | `platform_revenue` + `instructor_payable[i]` (one leg per instructor) |
| Payout reserved | `instructor_payable[i]` | `provider_in_transit[i]` |
| Payout succeeded | `provider_in_transit[i]` | `platform_cash` |
| Payout failed | `provider_in_transit[i]` | `instructor_payable[i]` (reversal) |
| Refund — unearned | `deferred_revenue[sub]` | `platform_cash` |
| Refund — earned | `platform_revenue` + `instructor_payable[i]` | `platform_cash` (clawback) |

A **release** from hold posts nothing. The hold is allocation state, not a ledger fact: no money
moves when an earning matures, only its eligibility changes. That is `R2`, and it is why
`earning_allocations` carries `available_at` / `released_at` rather than the ledger carrying a
`hold` account.

`LedgerService` is the only class that writes to `ledger_entries` — not a convention but a
testable claim, since nothing else references the table. Posting and the business state change it
records always share one transaction, and posting outside a transaction throws.

---

## Revenue allocation strategy

A payment of `price` for a term produces, in one database transaction:

1. a `subscriptions` row and a `payments` row keyed by a `UNIQUE external_ref`;
2. a complete schedule of monthly `accrual_periods`, half-open `[period_start, period_end)`, whose
   `gross_minor` values sum to `price` **exactly** — asserted before a row is written, so a term
   whose schedule does not add up cannot commit;
3. one `payment_received` ledger transaction.

Nothing has been earned yet. `deferred_revenue[sub]` equals the price.

Then, nightly, `ledger:accrue` takes every period whose window has closed:

```
gross                                        the period's slice of the price
  ├── pool     = floor(gross × share_bps / 10000)      70% by default
  └── platform = gross − pool                          the floor's remainder lands here

pool ──split by engagement minutes, largest remainder, tie-break ascending id──▶
       one earning_allocation per instructor, available_at = period_end + hold_days
```

and posts `DR deferred_revenue[sub]` / `CR platform_revenue` + one `CR instructor_payable[i]` per
instructor, with a balance delta each. When the hold matures, a second sweep moves the money from
`held` to `available` — no posting, just eligibility.

Three properties hold by construction rather than by care:

- **Σ allocations + platform = gross**, per period, checked as invariant I6.
- **Σ period gross = price**, per subscription, checked as I8 — and preserved through a truncating
  refund, because truncation lowers gross by exactly what the refund returns.
- **Re-running changes nothing.** The period's status moves `scheduled → recognized` by
  compare-and-swap, so the second run's `UPDATE` affects zero rows and the whole recognition is
  skipped. There is no intermediate `recognizing` status: a crashed run leaves the period
  `scheduled` and the next run picks it up, which is strictly better than a state that would need
  its own recovery path.

---

## Idempotency approach

Ten layers. The first nine are correctness and live in the database. The tenth is efficiency and
lives in Redis.

| # | Risk | Mechanism | Enforced by |
|---|---|---|---|
| 1 | Duplicate payment ingestion | `UNIQUE payments.external_ref` | database |
| 2 | Period recognized twice | `UNIQUE (subscription_id, period_start)` + status CAS | database |
| 3 | Instructor credited twice for a period | `UNIQUE (accrual_period_id, instructor_id)` | database |
| 4 | Duplicate ledger entry | `UNIQUE (entry_type, reference_type, reference_id, account_type, account_id)` | database |
| 5 | Two concurrent payout runs | `UNIQUE payout_runs.run_key` | database |
| 6 | Two payouts to one instructor in a run | `UNIQUE (payout_run_id, instructor_id)` | database |
| 7 | A re-run paying an already-paid balance | reserve-before-send: the balance leaves `available` atomically, inside the transaction that creates the item | database transaction |
| 8 | A retried job re-transferring | same `idempotency_key` → the provider dedups server-side | provider + database |
| 9 | An invalid state transition | conditional `UPDATE ... WHERE status = ?`, assert `affected === 1` | database |
| 10 | Wasted concurrent work | `Cache::lock`, `ShouldBeUnique`, `WithoutOverlapping` | Redis — **optimization only** |

The shape that recurs everywhere: **a unique index decides who wins, and the affected-row count is
how the loser finds out.** An `insertOrIgnore` returning 0 means "someone else already did this",
not an error. An `UPDATE ... WHERE status = ?` affecting 0 rows means "the state moved under me",
and the caller returns a no-op rather than throwing.

This is tested the only way it can be honestly tested: `tests/Concurrency/` opens **two real
database connections** and interleaves them by hand, so the race actually happens. Those tests
cannot use `RefreshDatabase` — it holds one transaction open for the whole test, so the second
connection could never see the first's rows and every test would pass for the wrong reason. They
use `DatabaseTruncation` instead and pay the speed cost (`R11`).

---

## The payout state machine

```
                  ┌──────────────── release ────────────────┐
                  ▼                                         │
 (payable)  ──▶ reserved ──submit──▶ submitted ──────▶ succeeded
                  ▲                      │
                  │                      ├──────────▶ failed        money returns to available
                  │                      │
                  └──── resend ──── unknown ──reconcile──▶ succeeded | failed
                                        │
                                        └──(ladder exhausted)──▶ needs_review
```

Every arrow is a conditional `UPDATE ... WHERE status = <expected>` asserting `affected === 1`.
That makes each transition idempotent on its own, and it means two workers racing the same item
cannot both act on it.

`needs_review` is a terminal state that only a human leaves, via `payouts:resolve`, which requires
a `--reason`. That command passes an explicit "from" status rather than widening the shared
compare-and-swap — widening it would let a retried job quietly resolve an item a person was asked
to look at, which is the one thing parking it there was meant to prevent (`R47`).

### The ordering rule

> **Commit the intent, then call the provider, then commit the outcome.**
> Never hold a database transaction open across a network call.

If the worker dies at any point after the intent is committed, the `payout_items` row and its
`idempotency_key` are already durable, so reconciliation can discover what really happened. A
transaction held across the HTTP call would either roll a real transfer out of the records or pin
a row lock for the provider's entire timeout.

This is not a comment. `ScriptedMockProvider` throws if it is called while a transaction is open
above the level it was constructed at, so violating the rule is a failing test rather than a code
review note.

---

## Provider timeout handling

The provider interface is two methods:

```php
interface PaymentProvider
{
    public function transfer(TransferRequest $request): TransferResult;
    public function getStatus(string $idempotencyKey): TransferResult;
}
```

`getStatus` exists because of `D-8`. Without it, an uncertain outcome would have to be guessed.

Both mocks dedup server-side on `idempotency_key` — a retry with the same key returns the
*original* result rather than transferring again. That is the whole point: it models what a real
provider does, and it is what turns at-least-once job delivery into effectively-exactly-once
payment. `RandomMockProvider` rolls weighted dice (success / permanent failure / timeout *after*
an internal success / delayed confirmation); `ScriptedMockProvider` consumes a queued sequence so
a test can say "this one times out after succeeding, and the retry then finds it" and mean exactly
that.

What happens on a timeout:

1. The item goes to `unknown`. **The money stays reserved** — it has left `available` and sits in
   `provider_in_transit`. Nobody can be paid it twice, and it is visible as in-flight.
2. `next_check_at` is set, and `payouts:reconcile` sweeps items past it on a backing-off ladder
   (1m → 5m → 30m → 2h → 6h).
3. The sweep calls `getStatus`. A definitive answer settles the item and posts either the success
   or the reversal. Another uncertain answer re-arms the ladder.
4. A `not_found` **after** the grace window — the provider has had time to see the transfer and
   reports no record of it — sends the item back to `reserved` for another attempt, with the same
   idempotency key. Even if the status API was lying, the provider's dedup stops a second payment.
5. When the ladder is exhausted the item goes to `needs_review` and stops. It is never re-sent
   blindly; a person decides, and `payout_attempts` records every interaction with the provider
   including the ones that changed nothing.

The claim "the money moved once" is not asserted from the application's own records, which would
be circular. It is asserted against the provider's internal `transfer_executions` counter, which
is incremented in exactly one place.

---

## Refunds

A refund carries an `effective_at`, which partitions the term into three zones:

| Zone | Periods | Treatment |
|---|---|---|
| Already recognized | `period_end <= effective_at` | Earned. A full refund claws it back; a pro-rata refund leaves it alone. |
| In flight | spans `effective_at` | Truncated to the refund date by whole days, its gross reduced by exactly what is returned. |
| Not yet started | `period_start >= effective_at` | Cancelled. The money is sitting in deferred revenue and never reached an instructor. |

Pro-rata is the common case and touches no instructor's earnings at all, which is the practical
payoff of `D-1`. A full refund cancels the term and claws back what was recognized; if that
exceeds the instructor's balance it carries forward negative (`D-7`).

`refunds:issue --dry-run` prints the plan without writing, and the dry run is verified to move
nothing — including `instructor_balances.updated_at`, so even a no-op write would show.

---

## Invariants, and the command that checks them

| | |
|---|---|
| **I1** | Σ all ledger amounts = 0 |
| **I2** | each `transaction_uuid` sums to 0 |
| **I3** | every snapshot field = its value recomputed from the ledger |
| **I4** | `outstanding = available + held + reserved`, per instructor |
| **I5** | `deferred_revenue[sub]` = 0 once every period is recognized or cancelled; never negative |
| **I6** | per recognized period: `platform + Σ allocations = gross` |
| **I7** | each succeeded payout item ↔ exactly one provider transfer **and** one `payout_reserved` entry |
| **I8** | per subscription: `Σ period gross + refunded = price` |

`ledger:verify` runs eight checks covering I1–I6 and the ledger half of I7 and I8. It streams by
keyset, so it is flat in memory over millions of rows.

I7's other half — "exactly one provider transfer" — is deliberately **not** in the verifier. The
provider's records are not in this database, and a verifier that reached into
`mock_provider_transfers` would be checking the mock rather than the system, passing in production
by finding nothing. That half is asserted in the chaos test instead (`R44`).

The chaos test is the strongest evidence here: a seeded random walk of 300 steps through every
entry point — subscribe, accrue, refund, run payouts, reconcile, resolve — asserting **all eight
invariants after every step**. It carries its own guard: a test that points the harness at a
deliberately corrupted database and requires it to go red, because "300 steps passed" reads
identically whether the checks work or not.

---

## Scaling considerations

| Concern | Approach |
|---|---|
| Iteration | Keyset pagination (`WHERE id > ?`) everywhere. No `OFFSET`, no `->get()` on an unbounded set. |
| Writes | Chunked bulk `insertOrIgnore`, 1 000 rows per statement, not per-row Eloquent saves. |
| Balance reads | The snapshot table, O(1). The ledger is never `SUM()`ed at request time. |
| Hot rows | `instructor_balances` moves by atomic `UPDATE ... SET x = x + ?`, never read-modify-write in PHP. |
| Fan-out | `Bus::batch()` over chunks, one job per payout item, on a dedicated `payouts` queue with limited concurrency to respect provider rate limits. |
| Indexes | `ledger_entries (account_type, account_id, id)`; `accrual_periods (status, period_end)`; `payout_items (status, next_check_at)`; `earning_allocations (released_at, available_at)`. |
| Growth | `ledger_entries` partitioned by month — **designed for, not implemented**. |
| Archival | Closed runs and settled items are cold-storable after N months — **designed for, not implemented**. |

### Measured, not asserted

`ScaleSeeder` writes a realistic slice so the numbers are real. On a development laptop
(PHP 8.4, MySQL, no tuning):

| Operation | Volume | Time |
|---|---|---|
| `ScaleSeeder` | 1.26 M rows | 52 s |
| `ledger:accrue --sync` | 149 388 periods recognized, 470 292 allocations written | 13 m 30 s (**≈184 periods/second**) |
| `ledger:verify` (8 checks) | 869 068 ledger entries across 199 388 transactions | 22 s |

The accrual figure is the honest one to scrutinise: it is **one database transaction per period**,
which is the conservative choice. A period's recognition writes a status CAS, N allocation rows, a
ledger transaction and N balance deltas, and keeping them in one transaction is what makes a
crashed run leave no half-recognized period behind. A per-chunk bulk variant would be several
times faster and is a real option; it is recorded as a trade-off rather than taken, because the
failure it would introduce — a partially recognized chunk — is the exact failure this system is
built to make impossible.

`ledger:verify` at 22 seconds over 869 000 entries is the number that matters most, because it is
the one an operator runs when they are worried.

---

## Known limitations

Named, with reasons. Silence would read as oversight.

| Limitation | Why it is out of scope, and what a real system would do |
|---|---|
| **An instructor who goes negative and stops teaching** leaves an unrecoverable balance (`D-7`). | Real systems cap this with the hold period, a reserve percentage, and a write-off process after N days. The mechanism is understood; it is a policy feature, not a design gap. |
| **No table partitioning or archival.** | Designed for (the indexes and the keyset iteration assume it) but not implemented — it is a week's work on its own and would not have changed any behaviour demonstrable here. |
| **Single currency.** The `currency` column exists on every money table and is checked for consistency, but no conversion exists. | Multi-currency is not "add a column": it is FX rates at recognition time versus payout time, and which of those the instructor bears. That is a design decision this brief does not ask for. |
| **No tax, VAT, withholding or 1099-equivalents.** | A real requirement, large, and orthogonal to revenue sharing. |
| **No instructor KYC or payout-account onboarding.** | A provider concern. `instructors.payout_account_ref` is the seam. |
| **Engagement is consumed pre-aggregated.** Raw event ingestion is not built. | The rollup table is the interface. Ingestion is a separate system with its own scaling story. |
| **No real payment intake.** Payments and refunds are recorded facts keyed by a `UNIQUE external_ref`. | The brief's story starts after the money moved. There is no inbound charge provider and no `pending` payment. |
| **No student-facing application** (`R25`, withdrawing `D-11`). | Zero weight in the brief, and the time went into scale, the admin panel and these documents. The stated cost is that Livewire skill shows only through Filament. |
| **`split_equally` for zero engagement is a documented dial, not an implementation.** | The DTO rejects it explicitly rather than silently falling through to the default. |
| **`ScaleSeeder` is not idempotent** and refuses to run against a non-empty table. | It derives ids from MySQL's consecutive-autoincrement guarantee, which breaks silently if one row is skipped — and would surface thousands of rows later as an integrity error nobody could place. A loud precondition is the honest form of that trade (`R42`). |
| **No CI workflow.** | A deliberate scope call, not an omission. The commands a CI job would run (`pint`, `phpstan analyse`, `php artisan test`) are in the README and are what was run before every commit. |

---

## Discussion: a plan change mid-term

*Not built. Included because accrual makes it almost mechanical, which is the point worth making.*

A student on an annual plan upgrades in month 5:

1. **Close the current period early.** Prorate the in-flight period to the switch date by whole
   days and recognize it normally. Everything already earned stays earned; no instructor is
   affected by a decision they had no part in. **This is the whole argument for `D-1`.**
2. **Compute the unearned remainder.** Future `scheduled` periods are cancelled and their gross is
   the student's credit. It is already sitting in `deferred_revenue` — no cash movement is needed
   to find it, because the liability was never treated as income.
3. **Apply the credit to the new plan.** Charge `new_price − credit`. If that is negative (a
   downgrade), issue **account credit**, not cash: credit keeps the money in deferred revenue
   where instructors can still earn it, and avoids provider refund fees and fraud surface.
4. **Write a new accrual schedule** from the switch date, with the same largest-remainder proration
   so the new periods sum to the new price exactly.
5. **One zero-sum ledger transaction:** debit the old deferred revenue, credit the new, and
   debit/credit cash for the difference. Auditable end to end, and no balance is rewritten.

The mechanisms this needs already exist: `AccrualSchedule` writes a schedule summing exactly to a
price, `RefundPlan` already partitions a term at a date and truncates the period that spans it,
and the ledger already handles a multi-leg zero-sum transaction. An upgrade is a refund's
partition plus a new schedule.

The open questions are worth more than the answers:

- Should an upgrade's unused **days** or unused **value** carry? They differ whenever plan-level
  discounts vary.
- Should a downgrade take effect immediately or at the next period boundary? Immediate is fairer
  to the student; the boundary is far simpler and cannot be gamed by switching repeatedly.
- Should the shortened period's instructor allocations be weighted by the partial period's
  engagement or by the full month's?

Each is a policy call with a real loser, which is why they belong in front of whoever owns the
policy rather than being decided quietly in a service class.
