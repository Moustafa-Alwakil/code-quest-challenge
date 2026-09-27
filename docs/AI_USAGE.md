# AI Usage

The brief allows AI assistance and requires disclosure, and warns that the review will ask for the
implementation to be explained and modified live. So this document is written to be checkable:
almost every claim below points at a file, a numbered refinement, or a commit you can open.

> **Before submitting — fill in the four items marked `TODO(candidate)`.** They are the personal
> claims no document can source from the repository, and they are exactly what a reviewer will
> probe. Everything else is evidenced.

---

## 1. How AI was used

<!-- TODO(candidate): name the tool(s), model(s), and the period. e.g. "Claude Code (Opus) over
     seven days, ~N sessions." Keep it specific; "used AI for boilerplate" is the answer the plan
     explicitly warns against. -->

Not as an autocomplete, and not as an oracle. The working model was:

**A decision log written before any code, then an environment that made the decided architecture
the path of least resistance, then implementation one feature file at a time, with every
divergence from the plan recorded as a numbered refinement.**

Three artefacts make that visible:

| Artefact | What it is |
|---|---|
| [`docs/PLAN.md`](PLAN.md) | The analysis and the decision log `D-1 … D-10`, written before a line of code. 796 lines, no implementation. |
| [`docs/features/`](features/) | Twelve feature specifications written from the plan, each naming the decisions it implements. |
| [`docs/features/README.md`](features/README.md) | **Fifty numbered refinements** (`R1 … R50`), each with where it applies and why it exists. This is the build's record of its own argument with the plan. |

The environment that constrained the agent is committed too, not gitignored — that is `R17`, made
deliberately so this document has evidence behind it. [`docs/AI_WORKFLOW.md`](AI_WORKFLOW.md)
describes it in full: twelve path-scoped rule files in `.ai/rules/`, five project skills, four
sub-agents with different tool scopes, and a routing table that says which tasks get which stages —
including the row that says a rename or a config change gets **no agent at all**, because forcing
every task through a five-stage chain is a failure mode, not thoroughness.

---

## 2. Main prompts and workflows

The prompt that mattered was not a prompt. It was `docs/features/NN-*.md` plus a stated discovery
order, written into `CLAUDE.md`:

```
1. The docs/features/NN-*.md file for the work, then docs/PLAN.md and the R-n refinements.
2. .ai/rules/index.md, then every rule file whose globs cover the paths in scope.
3. Existing sibling code — an established pattern beats a better idea.
4. Semantic search, only for code none of the above locates.
```

Point 3 is the load-bearing one. Left to itself, a capable model produces a *locally* better
version of each class and a globally inconsistent codebase. Ranking "what this repository already
does" above "what I would do" is what kept fourteen services looking like one author wrote them.

The per-feature loop was consistent:

1. Read the feature file, the plan sections it references, and the matching rules.
2. Plan the implementation and state the boundary calls explicitly — including the ones I was
   unsure about, flagged rather than hidden.
3. Implement with tests in the same pass, never as a follow-up.
4. `pint` → `phpstan analyse` (level 10) → the narrow test file → the full suite.
5. Record every divergence from the plan as an `R-n` refinement **with its reasoning**, then commit.

Step 5 is the one that compounds. By F09 the refinement log was a faster and more reliable source
than re-reading the plan, because it recorded what the code actually does and why it differs.

### What was deliberately *not* delegated

Nothing about money shape. The eight invariants, the largest-remainder tie-break, the entry
patterns, the state machine and the "unknown ≠ failed" decision were settled in `PLAN.md` before
the environment existed, and every later session was held to them rather than asked about them.

---

## 3. Generated versus designed

The distinction that matters is not "who typed it" but **"who would have to defend it".**

| Designed first, then implemented | Generated, then reviewed against the design |
|---|---|
| The ten decisions `D-1 … D-10` and their rejected alternatives | Migrations, factories, enums |
| The eight invariants and which are checkable where | Filament resources, table columns, badge colours |
| The double-entry patterns and per-subscription / per-instructor account keying (`R5`) | Test scaffolding, assertion plumbing, the seeders' bulk paths |
| The payout state machine and the ordering rule | Command signatures, output formatting |
| Every place a database constraint replaces application logic | Docblocks, and the prose in these documents |
| The layering contract and the arch tests that bind it | |

A useful marker: **the `.ai/rules/` files and the arch tests are the design; the code is the
implementation of them.** If an agent had produced different code that still passed
`tests/Feature/ArchTest.php` and `ledger:verify`, the design would have survived intact. That is
the claim, and it is testable.

---

## 4. Decisions made personally

<!-- TODO(candidate): confirm this list matches what you actually decided, and cut anything you
     would not want to defend cold in the review. A defended "wrong" call scores better than an
     undefended right one — PLAN.md §18. -->

These are the ones to be able to argue without notes, including the case for the other side:

1. **Accrual over payment-time recognition (`D-1`).** The single most consequential call. It makes
   refunds, clawbacks and mid-term upgrades tractable and it makes everything else harder. The
   discussion section of `ARCHITECTURE.md` exists to show what it buys.
2. **Engagement-weighted allocation (`D-2`).** `PLAN.md` §21 explicitly offered equal-split as the
   cheaper option if the week got tight. It did not get taken.
3. **Timeout is a third state, not a failure (`D-8`).** The decision that separates this from a
   CRUD application.
4. **Constraints over locks (`D-10`),** and the claim that follows: if Redis vanished, nobody gets
   paid twice. That claim is why the concurrency tests open two real connections.
5. **Zero engagement → platform retains (`D-3`),** knowing it is a coin-flip. Hence the config dial
   and the explicit rejection of the unimplemented alternative rather than a silent fallthrough.
6. **Withdrawing the student flow (`R25`)** — the one change made to `PLAN.md` itself. Zero weight
   in the brief; the time went to `ScaleSeeder`, the admin panel and these documents. The stated
   cost is that Livewire shows only through Filament.
7. **No CI workflow.** Offered and declined. The commands a CI job would run are in the README and
   were run before every commit; a green badge would not have added evidence.
8. **Both framework-default overrides withdrawn late (`R49`, `R50`).** `Date::use(CarbonImmutable)`
   and `useCurrent()` on ten tables were both working, tested and defensible. They were removed
   anyway because each changed a default for the whole process to buy a guarantee this application
   only needs in specific places. The cost was paid honestly: roughly twenty write sites now stamp
   their own timestamps, and the schema test asserts both that the database default is gone and
   that a real posting still leaves `updated_at` non-null.

---

## 5. What differentiates this solution

The plan predicted what a typical AI-generated submission ships, and it is worth naming because
every one of the four is the *easy* implementation:

> business logic in the UI layer · money maths on Eloquent models · Actions taking arrays ·
> a Redis lock mistaken for an idempotency guarantee

Four things here are unusual enough to be worth the reviewer's attention:

**`ledger:verify`.** Eight checks that recompute every balance from the ledger and assert the
snapshot agrees, streamed by keyset so it stays flat over millions of rows. Most submissions have
a balance column and no way to know whether it is right. 22 seconds over 869 000 entries.

**The chaos test.** A seeded random walk of 300 steps through every entry point, asserting all
eight invariants after every step — with a guard on the guard: a test that points the harness at a
deliberately corrupted database and *requires it to go red*, because "300 steps passed" reads
identically whether the checks work or not.

**The concurrency suite.** Two real database connections, interleaved by hand, with
`Interleaved::expectBlocked()` returning the exception so a test can assert that one session
actually blocked on the other. These cannot use `RefreshDatabase` — it holds one transaction open,
so the second connection could never see the first's rows and every test would pass for the wrong
reason (`R11`). That is the kind of false green this suite is built to avoid.

**Architecture as a failing test.** `tests/Feature/ArchTest.php` fails the build if an Action
queries, a DTO imports `Illuminate`, an entry point reaches past an Action, `App\Support` reads the
clock, or a class names a concrete Service instead of its contract. Guidance in a markdown file is
advice. This is enforcement, and it is why the architecture claim can be demonstrated in one
command rather than argued.

### Suggestions that were actually rejected, and why

Only what genuinely happened during this build. Each is traceable.

| Rejected | Why, and where it is recorded |
|---|---|
| **A `recognizing` status** between `scheduled` and `recognized` | A separate in-flight status can be stranded by the crash it was invented to survive. The compare-and-swap and the writes share one transaction instead; a crashed run leaves the period `scheduled` and the next run picks it up (`R3`). |
| **Ledger entries for the hold** | Tens of millions of rows carrying no information the allocation row does not already have. The hold is allocation state, not a ledger fact, so a release posts nothing (`R2`). |
| **Nullable key columns** in the idempotency indexes | MySQL treats NULLs as distinct, so one nullable column silently disables the entire unique guarantee. `NOT NULL` with a `0` sentinel for singleton accounts (`R4`). |
| **Chained period boundaries** (`period_end + 1 month`) | Drifts: Jan 31 → Feb 28 → Mar 28. Boundaries are computed from `term_start + k months` (`R10`). |
| **Deleting a provably dead code path** in F03 | `available`'s `− held` term could not do anything until F05 existed. Rather than delete it or fake a source to test it, it shipped with a test that would go red the day it became real — a stronger obligation than a comment (`R20`). |
| **Silencing a PHPStan level-10 error with a cast** | The allocator's return type was genuinely imprecise; it was fixed at the cause with a `@template TKey of array-key` rather than a cast or a baseline entry (`R32`). |
| **Letting the verifier check the provider** | A check reading `mock_provider_transfers` would be checking the mock, not the system, and would pass in production by finding nothing. I7 was split: the ledger half is a verify check, the provider half is asserted in the chaos test (`R44`). |
| **Widening a shared compare-and-swap** so `payouts:resolve` could reuse it | It would let a retried job quietly resolve an item a human was asked to look at — the one thing `needs_review` exists to prevent. The source status is passed explicitly at the single call site that means it (`R47`). |
| **A Filament test that passed by agreeing with zero** | `assertTableColumnStateSet()` reads the record handed to it, not the row the query produced, so a factory instructor with no balance fell through to the column's `default(0)` and the assertion passed for the wrong reason. Replaced with assertions against the resource's real query (`R40`). |
| **A bulk seeding path pretending to be idempotent** | It derives ids from MySQL's consecutive-autoincrement behaviour, which breaks silently if one row is skipped and surfaces thousands of rows later. It refuses to run against a non-empty table instead — a loud precondition rather than a quiet corruption (`R42`). |

Three bugs were found only by *running* things, which is worth saying plainly because reading the
code did not catch any of them:

- `Bus::batch()` overrides the queue each job declares for itself, so payout jobs silently landed
  on `default` instead of `payouts`. Only visible with a real worker running.
- A `Cache::shouldReceive('driver')` mock that resolved the cache store *inside* its own return
  closure, recursing until PHP exhausted 500 MB.
- `payout_items.created_at` filled by MySQL's `useCurrent()`, which made the stranded-item sweep
  untestable: `travelTo` moves the application's clock and not the database's, so the two diverged
  by exactly the amount travelled (`R35` — and eventually `R50`, which removed the database clock
  everywhere).

---

## 6. Trade-offs chosen

| Trade-off | Chosen | Cost, stated |
|---|---|---|
| One transaction per period at recognition | Correctness | ≈184 periods/second. A per-chunk bulk variant is several times faster and is documented as a real option; it was not taken because a partially recognized chunk is exactly the failure this system exists to prevent. |
| Five layers (entry → DTO → Action → Service → Model) | Predictable seams | More structure than the plan's own minimalism implies. It earns its place because the review asks for live modifications and predictable seams are what make that survivable — but the skills name the cases where a layer may legitimately be skipped rather than pretending there are none. |
| MySQL for tests, never SQLite | Testing the real thing | A slower suite and a setup step. Worth it: unique-violation behaviour, `FOR UPDATE` and transaction semantics are what is under test. |
| Concurrency tests with `DatabaseTruncation` | Races that actually happen | Much slower than transaction-wrapped tests, and they need their own suite and their own database discipline. |
| Contracts for every service (`R48`) | A real test seam | A new method now lands in two files. Accepted as the reminder that a service's public surface was always an interface, whether or not it was written down. |
| Keeping `PLAN.md` and `docs/features/` in the repository | Evidence | They are longer than the code in places and date quickly. Kept anyway: they show where the design changed under contact with reality, which a polished-after-the-fact document cannot. |
| Double-entry-lite rather than a full chart of accounts | Time | Scores lower on accounting design than a real journal structure would. The five account types cover every movement this system makes. |

### The honest limitations of this workflow

- **The environment was defined before the code existed,** so the rules describe a decided
  architecture rather than an inferred one. Where the code diverged, the refinement log is the
  reconciliation — but the rules could still drift from reality without a test noticing.
- **Rules and skills are advisory by construction.** Only the arch tests and the database
  constraints bind. That is the same argument this system makes about Redis locks, applied to
  itself: if something matters enough to be guaranteed, it has to end up as a test or a
  constraint.
- **Fifty refinements is a lot.** Some of them are the plan having been wrong; some are the
  implementation having been lazy first. The log does not distinguish, and it would be more useful
  if it did.

---

## 7. On the planning documents

`docs/PLAN.md` and `docs/features/` are **kept in the repository deliberately**, per `R17`.

They are honest evidence of the workflow: the decisions were made before the code, the feature
files were written before their implementations, and the fifty refinements record every place
reality pushed back. A reviewer can read `R22` and `R49` in sequence and watch a decision get made,
justified, relied on for six features, and then withdrawn with its reasoning intact.

The alternative — deleting them and presenting only polished output — would make this document
unverifiable, which is the opposite of what disclosure is for.
