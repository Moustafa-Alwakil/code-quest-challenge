# F12 — Test Infrastructure, Invariants & Chaos Test

> **Day:** continuous — infrastructure on Day 1, chaos test on Day 5
> **Depends on:** all · **Plan refs:** §14 · **Required item 5**
> **Grade areas:** Testing (10%); the evidence behind Correctness (25%) and Failure handling (20%)

## Goal

A suite that proves **the money is right**, not merely that the code runs. Each feature file
lists its own tests; this feature owns the infrastructure, the cross-cutting invariants, the
chaos test, the arch tests, and the evidence.

## Infrastructure

| Concern | Decision |
|---|---|
| Database | MySQL test DB (`code_quest_testing`) via `phpunit.xml`. **Not SQLite** — unique-violation behaviour, `FOR UPDATE`, generated columns and isolation differ. README says why. |
| Default reset | `RefreshDatabase` (transaction-wrapped, fast) |
| **Concurrency tests** | **Must not be transaction-wrapped** — a second connection cannot see the first's uncommitted rows, so the race under test never happens. Use `DatabaseTruncation` in a `concurrency` group. |
| Concurrency technique | Two named connections to the same database, interleaved by hand: A begins and inserts; B attempts the same insert with a low lock-wait timeout; A commits; assert one row. Deterministic, no `pcntl_fork`. |
| Queue | `sync` for end-to-end command tests; `Bus::fake` to assert dispatch; call `handle()` directly to simulate retries |
| Provider | `ScriptedMockProvider` bound in tests; `transferCount(key)` is the source of truth for "money moved once" |
| Clock | `travelTo` for hold periods, grace windows, backoff ladders |
| Randomness | seeded from `TEST_SEED` (or random and printed on failure) |
| **Invariant hook** | `assertLedgerBalanced()` runs the `ledger:verify` checks in-process, registered in `afterEach` for every money-touching test file. **Every money test implicitly asserts the global invariants.** |

## Invariants

| # | Invariant |
|---|---|
| I1 | Σ all ledger amounts = 0 |
| I2 | each `transaction_uuid` sums to 0 |
| I3 | every snapshot field = its recomputed value |
| I4 | `outstanding = available + held + reserved` per instructor |
| I5 | `deferred_revenue[sub]` = 0 once all periods are recognized or cancelled; never negative |
| I6 | per recognized period: `platform + Σ allocations = gross` |
| I7 | each succeeded payout item ↔ exactly one provider transfer and one `payout_reserved` entry |
| I8 | per subscription: Σ period gross = price (truncation preserves this with the refund included) |

## The chaos test — the headline

- **N ≈ 300 randomized steps** over the DemoSeeder base, seeded RNG. Each step picks one of:
  new subscription · advance clock 1–10 days · `ledger:accrue` · `payouts:run` (same or new
  key, sometimes twice) · dispatch a random reserved item's job twice · provider outcome drawn
  from all four behaviours · `payouts:reconcile` · pro-rata refund · full refund · simulated
  crash after a transfer.
- **After every step:** I1–I8.
- **At the end:** drain reconciliation until nothing is `unknown`, then assert per instructor:
  Σ provider transfers = `paid_minor`, and no instructor has two succeeded items in one run.
- **On failure:** print the seed and the step log so the exact run reproduces.
- Runs in under ~30s by default. `--group=soak` (N ≈ 5 000) is excluded from the default run.

This is the one test that cannot pass by accident. Show it on camera.

## Arch tests (Pest `arch()`)

`tests/Feature/ArchTest.php`. These are what make the layering (R13–R16) enforceable rather than
advisory — a violation is a red test, not a code-review opinion. Written on Day 1 alongside F01.

**Purity and queueing**

- `App\Support` (Money, Allocator, RevenueSplit) does not use `Illuminate`
- Livewire components, controllers and Filament resources do not use `DB` or `LedgerEntry`
  directly — money moves only through Actions
- Jobs implement `ShouldQueue`

**Layering (R16)**

| Rule | Assertion |
|---|---|
| Actions are use cases | `App\Actions` is final, has the `Action` suffix, exposes `__invoke` |
| Actions do not query | `App\Actions` does not use `App\Models` or the query builder (`DB::transaction` excepted) |
| DTOs are contracts | `App\DTOs` is `final readonly`; does not use `App\Models` or `Illuminate` |
| Services own persistence | `App\Models` is used only in `App\Services`, `App\Models` and `Database` |
| Entry points go through Actions | `App\Livewire` and `App\Console\Commands` do not use `App\Services` |

When an arch test fails, the code is wrong. Relaxing the test is an architecture decision, not a
fix — it goes through the Architect and is recorded as an `R-n` refinement.

## Required-proof map

| Brief requires | Test | Owner |
|---|---|---|
| Running the payout process twice never double-pays | `PayoutRunIdempotencyTest` | F06 |
| Retried jobs never double-pay | `ProcessPayoutItemRetryTest` | F07 |
| Unreliable provider responses never cause duplicate payments | `ProviderUncertaintyTest` | F08 |

## Video scenario → test map

| # | Scenario | Test owner |
|---|---|---|
| 1 | Running payouts twice | F06 |
| 2 | Duplicate job execution | F07, F06 concurrency |
| 3 | Worker retry after failure | F07 |
| 4 | Provider timeout | F08 |
| 5 | Success with delayed confirmation | F08 |
| 6 | Refund after payout allocation | F09 |
| 7 | Rounding edge cases | F01, F05 |

## Coverage

Meaningful over maximal. 100% on F01's classes; elsewhere the target is "every row of every
failure table has a test". Coverage via `pcov` is optional.

## Evidence

- Screenshot of `php artisan test` passing (and the chaos group) → `docs/evidence/`
- *Stretch:* a GitHub Actions workflow with a MySQL service container, so the repository shows a
  green check without the reviewer running anything
