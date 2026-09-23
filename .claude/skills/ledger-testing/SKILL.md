---
name: ledger-testing
description: "Test design for this Instructor Revenue Ledger codebase — Pest 4 against MySQL. Use when writing or reviewing any test: unit tests for money and allocation, Livewire component tests, Action and Service tests, integration tests for accrual, payouts, reconciliation and refunds, concurrency tests, architecture tests, and the chaos/invariant test. Covers the MySQL-not-SQLite requirement, the concurrency group and two-connection technique, the ledger invariants I1-I8, the three required idempotency proofs, provider mocking, clock control and seeded randomness. Layers on the Boost testing-best-practices skill, which covers naming, assertions, isolation and test data."
---

# Ledger Testing

Boost's **`testing-best-practices`** skill covers test naming, assertion choice, isolation, test
data and suite performance. Use it. This skill covers what it cannot know: **how to prove the money
is right in this system.**

The specification is `docs/features/12-testing-and-invariants.md`. Each feature file also lists its
own acceptance criteria — those are the tests, already written out.

## The standard

A passing suite is not the goal. The goal is that **the money is right**, and that the failure paths
behave. A test that asserts a method was called proves nothing about a piastre.

## Infrastructure

| Concern | Decision |
| --- | --- |
| Database | **MySQL** (`code_quest_testing`), never SQLite. Unique-violation behaviour, `FOR UPDATE`, generated columns and isolation all differ — and they are precisely what is under test |
| Default reset | `RefreshDatabase` (transaction-wrapped, fast) |
| Concurrency tests | **Must not be transaction-wrapped.** `DatabaseTruncation`, in the `concurrency` group |
| Queue | `sync` for end-to-end command tests; `Bus::fake()` to assert dispatch; call `handle()` directly to simulate a retry |
| Provider | `ScriptedMockProvider` bound in tests; `transferCount($key)` is the source of truth for "the money moved once" |
| Clock | `travelTo()` for hold periods, grace windows, backoff ladders |
| Randomness | seeded from `TEST_SEED`, printed on failure so a run reproduces exactly |

Create tests with `php artisan make:test --pest {name}`. Run the narrowest set that covers the
change: `php artisan test --compact --filter=...` or `vendor/bin/pest --filter=...`. Ask the user to
run the full suite once the feature's tests pass.

## Concurrency

A race cannot happen inside one transaction — the second connection cannot see the first's
uncommitted rows, so the test passes for the wrong reason (`R11`).

Use `DatabaseTruncation`, two named connections to the same database, interleaved by hand:

```
A: BEGIN, INSERT
B: attempt the same INSERT with a low lock-wait timeout
A: COMMIT
assert: exactly one row
```

Deterministic, no `pcntl_fork`.

## The invariants

Registered via `assertLedgerBalanced()` in `afterEach` for every money-touching test file, so every
such test implicitly proves all eight.

| # | Invariant |
| --- | --- |
| I1 | Σ all ledger amounts = 0 |
| I2 | each `transaction_uuid` sums to 0 |
| I3 | every snapshot field = its recomputed value |
| I4 | `outstanding = available + held + reserved` per instructor |
| I5 | `deferred_revenue[sub]` = 0 once all periods are recognized or cancelled; never negative |
| I6 | per recognized period: `platform + Σ allocations = gross` |
| I7 | each succeeded payout item ↔ exactly one provider transfer and one `payout_reserved` entry |
| I8 | per subscription: Σ period gross = price |

## The three required proofs

Named in the brief; they carry the grade.

| Proof | Method | Assertion |
| --- | --- | --- |
| A payout run twice never double-pays | run `payouts:run` twice with the same key | one run, one item per instructor, `transfer()` called once, `paid_minor` incremented once |
| Retried jobs never double-pay | dispatch the job twice; also handle → throw → handle | one `succeeded` item, two `payout_attempts` rows, **one** provider transfer |
| An unreliable provider never duplicates | scripted timeout-after-success, then `payouts:reconcile` | `unknown` → `succeeded`; provider's internal transfer count === 1 |

## Unit tests — no database

`App\Support` is pure, so test it as pure. This is where the strongest statements live:

- `largestRemainder`: sum-equals-total for hand-picked cases **and** as a property test over
  hundreds of random `(total, weights)` pairs.
- Degenerate inputs: one instructor, all-zero weights, a total of 1 piastre, a total of 0.
- Determinism: the same input yields identical output across repeated calls.
- Proration: 12 periods of an annual price sum to the price exactly, leap-year February included.
- `platform + Σ shares === gross` across a fuzzed range of `share_bps`.

## Layer by layer

| Layer | Test it as |
| --- | --- |
| DTO | Rarely worth a test of its own. Test a named constructor when it transforms (config lookup, derived field) |
| Action | The unit of business behaviour. Feature test with a real database — the constraints are the logic. Assert the resulting rows and the invariants, not the Service calls |
| Service | Only where the query itself is the risk: keyset pagination boundaries, `lockForUpdate` scope, atomic increments under concurrency |
| Livewire | Observable behaviour: renders, validates, refuses when unauthorized, triggers the Action, updates state, redirects, dispatches. Assert the database outcome, never a private method |
| Command / Job | End-to-end with `sync`; retry behaviour by calling `handle()` twice |

Test the Action through its public `__invoke` and the rows it leaves behind. If a test has to reach
into a private method, the boundary is wrong — say so rather than working around it.

## Livewire

```php
Livewire::actingAs($student)
    ->test(Checkout::class)
    ->set('planId', $plan->id)
    ->call('pay')
    ->assertHasNoErrors()
    ->assertRedirect(route('subscription'));

expect(Payment::count())->toBe(1);
expect($provider->chargeCount($intentKey))->toBe(1);
```

Authorization gets its own test: invoke the method as the wrong user and assert it is refused.
Never assert authorization by checking that a button is absent from the markup.

Feature tests for the five F11 screens are **smoke-level only** — renders, auth guard, happy path.
They carry no grade weight and must not consume time that belongs to the ledger tests.

## Architecture tests

`tests/Feature/ArchTest.php`. These are what make `.ai/rules` enforceable rather than advisory.

```php
arch('support is pure')
    ->expect('App\Support')->not->toUse('Illuminate');

arch('actions are final invokable use cases')
    ->expect('App\Actions')->toBeFinal()
    ->and('App\Actions')->toHaveSuffix('Action')
    ->and('App\Actions')->toHaveMethod('__invoke');

arch('actions do not query')
    ->expect('App\Actions')->not->toUse(['App\Models', 'Illuminate\Support\Facades\DB']);
    // DB::transaction is the one exception — allow it explicitly if the rule is too broad

arch('dtos are readonly contracts')
    ->expect('App\DTOs')->toBeReadonly()->toBeFinal()
    ->and('App\DTOs')->not->toUse(['App\Models', 'Illuminate']);

arch('only services touch eloquent')
    ->expect('App\Models')->toOnlyBeUsedIn(['App\Services', 'App\Models', 'Database']);

arch('entry points go through actions')
    ->expect(['App\Livewire', 'App\Console\Commands'])->not->toUse('App\Services');

arch('presentation does not touch the ledger')
    ->expect(['App\Livewire', 'App\Filament', 'App\Http\Controllers'])
    ->not->toUse(['Illuminate\Support\Facades\DB', 'App\Models\LedgerEntry']);

arch('jobs are queued')
    ->expect('App\Jobs')->toImplement(Illuminate\Contracts\Queue\ShouldQueue::class);
```

When an arch test fails, fix the code. Changing the arch test to accommodate a violation is an
architecture decision — escalate it to the `architect` agent.

## The chaos test

The headline. ~300 randomized steps over the `DemoSeeder` base with a seeded RNG: new subscriptions,
clock advances, `ledger:accrue`, `payouts:run` (same and new keys, sometimes twice), double-dispatched
jobs, all four provider behaviours, `payouts:reconcile`, pro-rata and full refunds, simulated crashes
after a transfer.

Assert I1–I8 **after every step**. At the end, drain reconciliation until nothing is `unknown`, then
assert per instructor: Σ provider transfers = `paid_minor`, and no instructor has two succeeded items
in one run. On failure print the seed and the step log.

Under ~30s by default; `--group=soak` (N ≈ 5000) is excluded from the default run.

This is the one test that cannot pass by accident.

## Reviewing a test

- Does it assert money, state and invariants — or only that nothing threw?
- Would it fail if the implementation were subtly wrong (off by one piastre, a lost remainder)?
- Does it prove the failure path, not just the happy path?
- Is it coupled to an internal method name instead of observable behaviour?
- Does every row of the feature's edge-case table have a test?
