# Feature Breakdown — Instructor Revenue Ledger

The reasoning lives in [`../PLAN.md`](../PLAN.md). These files split that plan into
buildable features. Each one stands alone: goal, scope, data model, components, rules, edge cases,
acceptance criteria, tests, and its hook into the video.

## Features

| # | Feature | Day | Depends on | Decisions | Required item |
|---|---|---|---|---|---|
| [F01](01-money-and-allocation.md) | Money & allocation primitives | 1 | — | D‑4, D‑5 | 2 |
| [F02](02-catalog-and-seed-data.md) | Catalog & seed data | 1 (+ after F04) | F01 | inputs for D‑2 | 1 |
| [F03](03-ledger-core.md) | Ledger core | 2 | F01 | D‑9 | 1 |
| [F04](04-subscriptions-and-accrual-schedule.md) | Subscriptions, payments & accrual schedule | 2 | F01–F03 | D‑1 | 1, 2 |
| [F05](05-revenue-recognition.md) | Revenue recognition, allocation & hold | 2 | F01–F04 | D‑1, D‑2, D‑3, D‑6 | 2 |
| [F06](06-payout-runs.md) | Payout runs & reservation | 3 | F03, F05 | D‑10 | 3 · proof #1 |
| [F07](07-payout-execution-and-provider.md) | Provider, mock & payout execution | 4 | F06 | D‑8, D‑10 | 3, 4 · proof #2 |
| [F08](08-payout-reconciliation.md) | Payout reconciliation | 4 | F07 | D‑8 | 4 · proof #3 |
| [F09](09-refunds-and-clawbacks.md) | Refunds & clawbacks | 5 | F04, F05 (F06–F08) | D‑7 | brief: refunds |
| [F10](10-filament-admin.md) | Filament admin (read-only) | 6 | F03, F06 | — | 6 |
| [F12](12-testing-and-invariants.md) | Test infrastructure, invariants & chaos | continuous | all | — | 5 |
| [F13](13-submission-package.md) | Docs, video & submission | 6–7 | all | — | docs, video |

## Build order

```
F01 Money ──┬──► F03 Ledger ──► F04 Subscriptions ──► F05 Recognition ──► F06 Runs ──► F07 Execution ──► F08 Reconcile
F02 Catalog ┘                                               │                                              │
                                                            └──────────────► F09 Refunds ◄────────────────┘

F10 Filament ← F03, F06          F12 Testing ← runs alongside everything          F13 Submission ← Days 6–7
```

**If time runs short:** `ScaleSeeder` and `PayoutRunResource` go first — **never** the tests or the
docs (PLAN §16).

## Decision → feature map

| Decision | Features |
|---|---|
| D‑1 Accrual over the term | F04 (schedule), F05 (recognition), F09 (why refunds are cheap) |
| D‑2 Engagement-weighted split | F05 (F02 supplies the data) |
| D‑3 Zero engagement → platform | F05 |
| D‑4 Integer minor units | F01 |
| D‑5 Largest remainder | F01, used in F04, F05, F09 |
| D‑6 Hold period | F05 (release), F09 (free clawbacks) |
| D‑7 Negative balance carry-forward | F09, F06 (skips negatives) |
| D‑8 Unknown ≠ failed | F07, F08 |
| D‑9 Append-only double-entry ledger | F03 |
| D‑10 Constraints for correctness, locks for efficiency | F03, F06, F07 |
| ~~D‑11 Thin student flow~~ | withdrawn — R25 |

## Refinements to PLAN.md

Writing each feature at build level surfaced gaps in the plan. `PLAN.md` is left as it is — the one
exception is withdrawing D‑11 (R25) — and the refinements live in the feature files and are collected here so `ARCHITECTURE.md` picks them up.

| # | Refinement | Where | Why |
|---|---|---|---|
| R1 | Pro-rata refunds never claw back from instructors; clawbacks only arise from full refunds | F09 | Unused time = unrecognized periods under accrual. Sharpens PLAN §11. |
| R2 | The hold is tracked on allocation rows + a `held` snapshot field, not as ledger entries | F03, F05 | PLAN didn't say how the hold was represented; ledger entries would add tens of millions of rows for no new information |
| R3 | No `recognizing` status — the CAS and the writes share one transaction | F05 | A separate status can be stranded by a crash (PLAN §7) |
| R4 | `account_id` / `reference_id` NOT NULL; `0` for singleton accounts | F03 | MySQL unique indexes treat NULLs as distinct — a nullable key column silently disables idempotency |
| R5 | `deferred_revenue` keyed per subscription; `provider_in_transit` per instructor | F03 | Makes "each liability returns to zero" and "reserved per instructor" provable |
| R6 | Per-instructor revenue share dropped | F05 | Changes the order of rounding operations; documented as an extension |
| ~~R7~~ | *Withdrawn with the student flow (R25).* ~~`payments.idempotency_key` + one-live-subscription generated column~~ | — | Only checkout needed them; payments are now keyed by UNIQUE `external_ref` (F04) |
| R8 | Mock providers persist to a table | F07 | "Discover the result later" must work across worker processes |
| R9 | Payout items born `reserved`; no `pending` state | F06 | Creation and reservation share a transaction |
| R10 | Period boundaries computed from `term_start + k months`, not chained | F04 | Chaining drifts (Jan 31 → Feb 28 → Mar 28 …) |
| R11 | Concurrency tests cannot use transaction-wrapped `RefreshDatabase` | F12 | The second connection can't see uncommitted rows, so the race never happens |
| R12 | Status-first on job retry; `not_found` grace window before resending | F07, F08 | Robust against providers with weaker dedup or lagging status APIs |

### Architecture & workflow refinements (Day 0)

Recorded when the AI development environment was defined. These change *how* the code is written,
not what it does.

| # | Refinement | Where | Why |
|---|---|---|---|
| R13 | Action classes carry the `Action` suffix: `RecognizeAccrualPeriodAction`, `ReserveInstructorBalanceAction`, `ApplyProrataRefundAction`. The unsuffixed names used throughout F04–F10 all gain it | all features | One unambiguous name per layer; an arch test can assert it. `PLAN.md` §15's list reads with the suffix |
| R14 | Two new layers between the entry point and the models: `app/DTOs/**` (`final readonly` input contracts) and `app/Services/**` (the only place Eloquent and `DB` are touched). Actions orchestrate a use case and own the transaction boundary; they never query | all features | Keeps PLAN §12's keyset pagination, chunked `insertOrIgnore` and atomic increments out of the use-case layer, and makes every Action unit-testable against a faked Service. Services are grouped per aggregate (~6–8), never one per Action |
| R15 | The entry point is generalized: **Livewire component · Artisan command · queued job · Filament page → DTO → Action → Service → Model.** Commands and jobs are held to the same contract as a component; jobs carry scalar ids and rebuild the DTO in `handle()` | F05–F09 (commands/jobs), F10 | Most of the graded surface enters through commands and jobs, not Livewire. A Livewire-only rule would govern only F11 — which was later withdrawn entirely (R25) |
| R16 | Arch tests enforce the layering, extending the three already listed in F12 | F12 | Turns the layering from advice into a red test. Listed in full in F12 |
| R17 | The AI development environment — `.ai/rules/`, `.claude/skills/`, `.claude/agents/`, `CLAUDE.md`, `boost.json` — is committed, not gitignored | F13 | It is the evidence behind `docs/AI_USAGE.md` and the AI-transparency segment of the video. See `../AI_WORKFLOW.md` |

### Ledger core refinements (F03 architecture review)

Recorded when F03's implementation was reviewed. Each is a boundary decision the rest of the build
inherits, so none of them should be re-litigated feature by feature.

| # | Refinement | Where | Why |
|---|---|---|---|
| R18 | A Service may call another Service, but only to keep one invariant atomic, and only downward through an acyclic graph: `LedgerService::post()` calls `InstructorBalanceService::applyDeltas()` itself rather than returning a boolean for each Action to act on. The called Service still opens no transaction | F03, F06–F09; `.ai/rules/services.md` | The linkage "deltas apply *only if* the legs were inserted" is the whole point of `post()`. Pushing it up to the Action layer would repeat that obligation in every one of F05–F09, and each omission would be silent snapshot drift |
| R19 | `instructor_balances.last_ledger_entry_id` is a debugging watermark, not a verified value. `ledger:verify` does not check it and no code may branch on it — a `@property` docblock says so. F06 does not get to read it for "this balance is current as of entry X"; that guarantee is a new design with its own check, escalated when it is actually needed | F03, F06 | The column is written as `greatest(existing, last id of the posting)`, which is monotonic but does not mean "every entry at or below this id is folded in". A money decision must not rest on a value the verifier never proves |
| R20 | `held` recomputes to 0 until F05 supplies `earning_allocations` (R2), so `available`'s `− held` term is provably dead in F03 and three `BalanceDelta` constructors cannot be used yet: `recognized()` without a matching `released()`, `released()` without a prior `recognized()`, and `clawedBack()` with anything in `fromHeldMinor`. F03 ships with the dead term rather than deleting it | F03, F05 | No test can distinguish `x − 0` from `x`, so the gap is structural, not a missing assertion; faking a held source to kill the mutant would be an abstraction whose only caller is a test. `tests/Feature/Ledger/HeldBalanceGapTest.php` goes red the day F05 makes `held` real, which is a stronger obligation than a comment |
| R21 | `ledger:verify` check 3 also compares `instructor_balances.currency` against the single currency of that instructor's ledger entries (skipped when they have none), and the command returns exit 2 (`Command::INVALID`) — not 0 — when `--instructor=` was given and nothing was checked | F03 | `currency` is a snapshot field, and F03's own words are "every snapshot field = its recomputed value". A verify run that reports success over zero rows is the failure mode the counts in the output exist to prevent; exit 2 keeps exit 1 meaning "the money is wrong" |
| R22 | `Date::use(CarbonImmutable::class)` in `AppServiceProvider::boot()`, so Eloquent date attributes really are immutable | F03 onward, F04 especially | Every `@property CarbonImmutable $created_at` in the models is currently false — Eloquent returns mutable `Illuminate\Support\Carbon` — and PHPStan level 10 trusts the annotation. F04's `term_start + k months` boundary maths (R10) would mutate a model attribute in place with no error reported |

### Convention refinements (F03 review, decided by the maintainer)

Both reverse a call made during the F03 review. Recorded because an agent already "corrected" one
of them once this session on the strength of the older written rule.

| # | Refinement | Where | Why |
|---|---|---|---|
| R23 | Custom Eloquent builders stay in `App\Builders`, named with a `QueryBuilder` suffix (`InstructorQueryBuilder`), and `App\Builders` is admitted to the `only services touch eloquent` arch test — extending F12's literal list by one entry | F02, F03, F12 | A builder is a model's own query surface and names its model by necessity. The suffix is what keeps a top-level namespace from reading as a use-case layer, which was the objection to leaving it there. Nothing in `App\Actions`, `App\Livewire` or `App\Console\Commands` is admitted |
| R24 | Enum keys are `SCREAMING_SNAKE_CASE` (`PLATFORM_CASH`, `PERIOD_RECOGNIZED`, `ACTIVE`); backing string values stay `snake_case`. Overrides the TitleCase rule in the Boost-generated block of `CLAUDE.md`, via the hand-written `# PHP Conventions` section below it | all features | Enum cases are constants and the case says so at the call site. The override lives outside `<laravel-boost-guidelines>` because `boost:install` regenerates that block, so an edit inside it would be silently lost |

### Subscription & accrual refinements (F04 architecture review)

Recorded when F04's implementation was reviewed. R26 fixes a live defect; R27 and R28 settle
boundaries F05 would otherwise re-decide.

| # | Refinement | Where | Why |
|---|---|---|---|
| R26 | A date-typed input to `App\Support` is normalized to **midnight UTC from the caller's calendar date** by the function that consumes it — `CarbonImmutable::parse($input->toDateString(), 'UTC')` — never by converting the instant (`->utc()`) and never by `startOfDay()` in the caller's zone. `AccrualSchedule::forTerm()` does this to its anchor, so every boundary is a true midnight UTC instant and `days` is the whole calendar distance for any caller in any timezone | F04, F05, F09; `.ai/rules/support.md` | `startOfDay()` in a zone whose DST shift removes local midnight returns 01:00, and `(int) $start->diffInDays($end)` then truncates a 30-day period to 29 — a wrong largest-remainder weight, a `term_days` short by a day, and a `days` column that contradicts its own `period_end − period_start`. `Σ gross === price` still holds, so nothing else in the suite notices. `App\Support` may not read `config('app.timezone')` and `round`/`floor`/`ceil` are banned there, so the precondition has to be established by construction rather than documented, clamped or pushed onto the caller. `->utc()` is not the fix: it moves an early-morning Cairo capture to the previous day — and a late-evening Honolulu one to the next — and would split `term_start` from the `captured_at` it is defined to equal. The direction matters: for a zone ahead of UTC the date slips on 00:30, not on 23:00, so a 23:00 case is exactly the one that would fail to catch the mistake |
| R27 | A DTO may read the clock, but only inside a named constructor, only to default an absent input or to reject one that could not have happened yet, and the resolved instant is then carried as a field. Never in a plain constructor; never twice in one construction such that two fields could disagree; and never on a job-side rebuild — a job carries the already-resolved value as a scalar, so a retry acts on the window its dispatch intended. `CarbonImmutable::now()`, not `now()`, which returns a mutable `Illuminate\Support\Carbon` | F04 (`ExpireSubscriptionsData`), F05–F09; `.ai/rules/dtos.md` | `ExpireSubscriptionsData` reads the clock and is harmless because the sweep moves a cosmetic status. F05's `--date=today` decides which periods are recognized and must refuse a future date, so the same shape there is a money decision: resolve once at the boundary, carry it, and a chunk job that re-defaults `--date` in `handle()` cannot recognize a different set of periods on its second attempt. Tests control both with `travelTo()` |
| R28 | `accrual_periods` carries `created_at` / `updated_at` maintained by MySQL (`useCurrent()`, `useCurrentOnUpdate()`), beyond the column list F04 names | F04, F05, F12 | The rows are written by a multi-row `insertOrIgnore` and updated by F05's conditional `UPDATE`, neither of which fires model events, so Laravel's `timestamps()` would leave both columns null. The feature files list the load-bearing columns, not the DDL — and these two are load-bearing in their own right: "a replay touched no period" is proved by an unchanged `updated_at`, and F05's recognition run gets a recency watermark for free |

### Recognition & hold refinements (F05 implementation)

Recorded when F05 was built. R29 and R30 extend boundaries F03 set; R31 and R32 are calls the
feature files left open.

| # | Refinement | Where | Why |
|---|---|---|---|
| R29 | `InstructorBalanceService` takes `EarningAllocationService` as a constructor dependency, so `recomputeFromLedger()` sources `held` from the allocation rows itself. This extends R18 — which sanctions Service-to-Service calls only for holding one invariant atomically — to a **downward, read-only** composition: allocations know nothing about balances, the graph stays acyclic, and neither Service opens a transaction | F03, F05; `.ai/rules/services.md` | `held` is the one snapshot field the ledger cannot answer for, because the hold posts no entries (R2). F03's own docblock names this edit as the intended one. The alternative — `LedgerVerificationService` sourcing the totals and passing them in — keeps composition at the layer that already composes, but makes "recomputes every balance field from the ledger" a half-truth and moves a definition of `held` out of the aggregate that owns it. The read/write distinction is the line: R18's "only to keep one invariant atomic" governs writes, where a missed call is silent drift; a read has no such failure mode |
| R30 | `ledger:verify` check 5 asserts `deferred_revenue[sub] === Σ gross of the periods still scheduled`, at every moment — not the weaker "0 once every period is recognized or cancelled, never negative" that F03 and I5 state. It iterates **subscriptions**, not `deferred_revenue` accounts | F03, F05, F12 | The stronger form subsumes both halves of I5 (a sum of gross is never negative, and is 0 when the term is delivered) and catches a recognition that posted a different gross than it recognized *on the day it happens*, rather than eleven months later when the term closes. Iterating subscriptions also scopes the check to the thing the invariant is a statement about: an account keyed to no subscription — which is what every hand-built F03 posting fixture is — is outside what the check can say anything about, and flagging those would have forced 36 ledger-mechanism tests to grow a subscription and a payment they are not testing |
| R31 | `zero_engagement_policy` is a `ZeroEngagementPolicy` enum resolved in `AccrueRevenueData::fromCommand()`, and an unimplemented value is **rejected** there rather than falling through to `platform_retains`. The backing value is `split_equally`, the spelling `tests/Feature/RevenueConfigTest.php` already guards — F05's prose says `split_enrolled` and `config/revenue.php`'s comment says neither; the executable assertion wins and the doc drift is F13's to tidy | F05; `config/revenue.php` | D-3 exists as a dial to make the decision visible, and a dial that silently does the other thing when set is worse than no dial. Retaining revenue for the platform under a setting that asked for it to be shared is the worst available reading of a money config, and it is unobservable: every invariant still holds, because retaining is *a* consistent policy. Refusing at the boundary makes the gap between "documented" and "built" a startup failure with a message, which is the only place it can be noticed |
| R32 | `Allocator::largestRemainder()` is generic in its key type (`@template TKey of array-key`), so `array<int, int>` in yields `array<int, int>` out | F01, F05, F09 | The docblock already promised "every input key, in input order"; the annotation flattened it to `array-key`, which is a weaker statement than the function makes. Callers weight by instructor id and then look the result up by that same id, so under PHPStan level 10 the flattened return is a real error at every call site — and the fixes available without a template (a cast, a widened parameter, an inline `@var`) are all the suppression that `CLAUDE.md` forbids. The template is the cause fixed rather than the symptom |

### Payout run refinements (F06 implementation)

Recorded when F06 was built. R33 fills a gap the feature file's finalization rule leaves open.

| # | Refinement | Where | Why |
|---|---|---|---|
| R33 | A run still holding `reserved` items finalizes to **`dispatched`**, not `completed_with_pending`. F06's rule covers only terminal statuses (→ `completed`) and `submitted` / `unknown` / `needs_review` (→ `completed_with_pending`), and says nothing about the status every item is born in | F06, F08 | The two statuses make different claims. `completed_with_pending` says "everything was sent and we are waiting to hear", which is what D-8 reserves it for; a `reserved` item has not been sent at all, and is waiting for a worker rather than for the provider. Calling that `completed_with_pending` would put a run whose jobs never ran into the same bucket as one blocked on a provider timeout — and F08's reconciliation sweep, which re-finalizes runs as `unknown` items resolve, would find nothing to reconcile and leave the run permanently mislabelled. `dispatched` is also what lets a resumed run be told apart from a finished one without counting items |

### Payout execution refinements (F07 implementation)

Recorded when F07 was built. R34 is a testing-infrastructure decision F08 inherits.

| # | Refinement | Where | Why |
|---|---|---|---|
| R34 | `ScriptedMockProvider` enforces the §8.2 ordering rule against the transaction depth it was **constructed at**, not against zero: it records `DB::transactionLevel()` in its constructor and refuses any `transfer()` above it. F12's "no DB transaction is open during `transfer`" check is therefore "no transaction *we opened* is open" | F07, F08; `docs/features/12-testing-and-invariants.md` | `RefreshDatabase` holds one transaction open for the whole test, so a literal `level === 0` assertion fails every feature test and proves nothing — while deleting the assertion loses the only automated guard on the rule that keeps a row lock from being pinned for a provider's entire timeout. The baseline is 0 in production and 1 under `RefreshDatabase`, and a genuine violation is baseline + 1 in both. The one fragility is real and worth stating: if the singleton were first resolved *inside* a transaction, that transaction becomes its baseline and the guard is neutered. Every production path resolves it from a job or a command, outside one; `MockProviderTest` resolves it explicitly before opening a transaction, with a comment saying why |

### Reconciliation refinements (F08 implementation)

Recorded when F08 was built. R35 reverses, for one column, the choice R28 made for another.

| # | Refinement | Where | Why |
|---|---|---|---|
| R35 | `payout_items.created_at` is stamped by the application (`CarbonImmutable::now()` in `PayoutRunService::createReservedItem()`), not left to the column's `useCurrent()` default. A timestamp a money decision is **measured from** is set by the application; one that only **evidences** a write stays with MySQL (R28) | F06, F08 | F08's stranded sweep asks "has this item sat reserved for half an hour", which compares an application instant against this column. With `useCurrent()` those are two different clocks deciding one money question — and under `travelTo` they diverge by whatever the test travelled, so the sweep could not be tested at all. R28's reasoning is untouched and still right for `accrual_periods`: there the timestamps prove *that* a write happened ("a replay touched no period" is an unchanged `updated_at`), and the database is the correct authority for that. The distinction is measured-from versus evidence-of, and the column keeps its default for any row written outside that method |

### Refund refinements (F09 implementation)

Recorded when F09 was built. R36 departs from the feature file's prose; R37 and R38 pin
consequences it leaves implicit.

| # | Refinement | Where | Why |
|---|---|---|---|
| R36 | A clawback sets `earning_allocations.clawed_back_at` on **released** allocations as well as held ones. F09's component list sets it only on the not-yet-released ones | F09, F10 | `clawed_back_at` is the record that an earning was reversed, and "which of this instructor's earnings were taken back" must not return a different answer depending on whether the hold happened to have expired first — F10's screen and any later audit both read that column. Nothing downstream changes: `held` is the sum of allocations with neither timestamp set, so a released row was already outside it, and check 6 counts clawed-back allocations either way. The snapshot split between `held` and `available` is still decided by `released_at`, exactly as F09 specifies |
| R37 | `refunds:issue --external-ref=` is **required**, not optional as its listing among the options suggests. A blank one is rejected at the DTO | F09 | A refund with no gateway id has no idempotency key, and generating one would be inventing the very fact the row exists to record (R25). UNIQUE `external_ref` would still hold, but only against other refunds that happened to carry a reference — the guarantee would quietly stop applying to precisely the calls that most need it |
| R38 | A pro-rata refund recognizes the truncated period through F05's action, and therefore inherits F02's late-data policy: engagement not yet rolled up for that period is not counted, and a period with none recognizes entirely to the platform under D-3 | F02, F05, F09, F13 | The alternative — a refund waiting for, or triggering, an engagement rollup — makes a refund depend on a batch job, which is worse for both the student and the instructor. The consequence is small in practice (the straddled period is days old at most) but it is a real one, and it is the kind of thing that should be in the written limitations rather than discovered from the code |

### Admin panel refinements (F10 implementation)

Recorded when F10 was built. R39 extends R23's admission by one namespace; R40 is a testing trap
worth naming.

| # | Refinement | Where | Why |
|---|---|---|---|
| R39 | `App\Filament\Admin\Resources` is admitted to the `only services touch eloquent` arch test, on R23's reasoning: a resource *is* a model's read-only admin surface and Filament binds the two by class name. Two narrower rules are added in exchange — `App\Filament` may not use `DB`, `App\Models\LedgerEntry` or `App\Models\EarningAllocation`, and may not use `App\Services` at all | F10, F12; `.ai/rules/filament.md` | Filament is a model-driven framework and `protected static ?string $model` is not optional; the alternative was passing the class as a string to dodge a test, which is worse than recording the decision. What the original rule protects is untouched: the panel still cannot aggregate the ledger at request time, which is the thing that would quietly turn the screen into a second opinion about the money instead of a window onto the snapshot. Nothing in `App\Actions` or `App\Console\Commands` is admitted, and an operator action that moved money would still have to go through an Action with a DTO |
| R40 | Filament's `assertTableColumnStateSet()` reads the record **handed to it**, not the row the table query produced. Assertions about joined or sub-selected columns go against `Resource::getEloquentQuery()` directly, and the rendered figures are asserted with `assertSee()` | F10, F12 | A factory-made `Instructor` has no `held_minor` attribute, so the helper falls through to the column's `default(0)` and the assertion passes by agreeing with zero — silently, and for every balance that happens to be zero as well. This cost a debugging round on F10's first green-looking run, and every later screen reading a joined snapshot will meet it again |

## Definition of done — every feature

- [ ] Migrations include every constraint the feature lists
- [ ] The feature's tests pass against MySQL
- [ ] `ledger:verify` green after the feature's tests (from F03 onward)
- [ ] One commit (or a small series) with a meaningful message
- [ ] Decision-log note added for anything AI suggested that you changed or rejected

### Scope refinement (decided by the maintainer)

| # | Refinement | Where | Why |
|---|---|---|---|
| R25 | **D‑11 withdrawn: no student flow.** No auth screens, enrolment UI or checkout; F11 and the `livewire-feature-development` skill are deleted. Payments and refunds are recorded as captured facts keyed by a UNIQUE `external_ref` (F04, F09), so there is no inbound charge provider and no `pending` payment. `enrolments` stays as seeded catalog data — engagement is generated only for enrolled courses. R7 is withdrawn with it. This is the one change made to `PLAN.md` itself | PLAN §1, §4, §13–§17, §20; F02, F04, F09, F10, F13; `.claude/skills`, `.ai/rules/dtos.md`, `config/revenue.php` | The brief's story starts after the student has paid and grades no UI beyond one read-only screen. The time returns to `ScaleSeeder`, `PayoutRunResource` and the docs. Trade-off, stated openly: Livewire skill now shows only through Filament |
