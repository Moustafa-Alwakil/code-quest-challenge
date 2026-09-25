---
name: revenue-ledger-architecture
description: "The layering contract for this Instructor Revenue Ledger codebase. Use whenever writing or reviewing application code in app/ — a Livewire component, Artisan command, queued job, Filament action, Action, DTO, Service, Model or Support class — and whenever deciding where a piece of logic belongs. Covers the entry point → DTO → Action → Service → Model chain, naming, transaction boundaries, the idempotency ladder (database constraints vs. locks), integer money handling, and the rules for when a layer may legitimately be skipped. Do not use for Livewire 3 syntax (see livewire-development), test design (see ledger-testing) or generic Laravel practice (see laravel-best-practices)."
---

# Revenue Ledger Architecture

The money core of this application is graded on correctness, failure handling and design
(`docs/PLAN.md` §1). The layering below exists to make those three provable, not to add ceremony.
Read the relevant `docs/features/NN-*.md` file before writing code — it is the specification.

## What Laravel Boost already covers — do not restate it

| Concern | Boost skill |
| --- | --- |
| General Laravel practice, Eloquent, validation, queues, security | `laravel-best-practices` (see its `rules/` directory) |
| Livewire 3 syntax, directives, lifecycle hooks, Alpine bundling | `livewire-development` |
| Test naming, assertions, isolation, test data | `testing-best-practices` |
| Tailwind utilities and layout | `tailwindcss-development` |

**One deliberate override.** Boost's `laravel-best-practices/rules/architecture.md` shows
`CreateOrderAction::handle(array $data)`. This project does **not** do that. Actions here are
`final`, invokable, and take a DTO. Where Boost and this skill disagree, this skill wins; where
this skill is silent, Boost applies.

## The chain

```
Entry point            Livewire component · Artisan command · queued job · Filament page/action
      ↓                validate, authorize, build the input contract
    DTO                final readonly, scalars only
      ↓
   Action              one use case, invokable, owns the transaction boundary
      ↓
  Service              the only layer that touches Eloquent and DB
      ↓
   Model               relationships, casts, scopes
```

`App\Support` (`Money`, `Allocator`, `RevenueSplit`) sits beside the chain: deterministic functions
any layer may call, with no I/O. It is ordinary Laravel code — use `Illuminate\Support\Str` and
`Number` there in preference to native string and number functions, as everywhere else in `app/`.

Full responsibilities, worked examples and the anti-patterns each layer attracts:
**`references/layering.md`**.

## Naming

| Layer | Namespace | Shape |
| --- | --- | --- |
| Filament | `App\Filament\Resources` | `InstructorResource` — read-only |
| Command | `App\Console\Commands` | signature `payouts:run`, `ledger:accrue` |
| Job | `App\Jobs` | `ProcessPayoutItemJob` |
| DTO | `App\DTOs\<Domain>` | `RunPayoutsData`, `RecognizePeriodData` — `Data` suffix |
| Action | `App\Actions\<Domain>` | `ReserveInstructorBalanceAction` — `Action` suffix, verb first |
| Service | `App\Services` | `InstructorBalanceService` — one per aggregate |
| Model | `App\Models` | `PayoutItem`, `LedgerEntry` |

Domains: `Accrual`, `Ledger`, `Payouts`, `Refunds`, `Subscriptions`.

Name an Action for the concrete operation — `ApplyProrataRefundAction`, `SettlePayoutItemAction`.
Never `PayoutAction`, `LedgerAction`, `CommonAction`: a noun bucket becomes a god class within a day.

> The feature docs in `docs/features/` name Actions without the suffix (`RecognizeAccrualPeriod`).
> Refinement R13 in `docs/features/README.md` adds it. Read the docs' names as suffixed.

## Transaction boundaries

The Action owns `DB::transaction()`. Services do not open transactions, so an Action can compose
several Service calls into one atomic unit.

**The ordering rule for anything involving a provider** (`docs/PLAN.md` §8.2):

```
1. commit the intent          (row + idempotency_key are durable)
2. call the provider          OUTSIDE any transaction
3. commit the outcome         (conditional UPDATE, assert affected === 1)
```

Never hold a transaction open across a network call. A transaction that spans the HTTP request
either rolls a real transfer out of the records, or pins a row lock for the provider's entire
timeout — both are fatal at this scale.

## Idempotency

Correctness comes from the database. Locks only stop wasted work.

- Every "must happen once" fact has a `UNIQUE` index; writes use `insertOrIgnore`.
- Every state transition is `UPDATE … WHERE status = <expected>` with `affected === 1` asserted.
- `Cache::lock`, `ShouldBeUnique`, `WithoutOverlapping` are optimizations. If Redis vanished, no
  instructor would be paid twice — keep that true.

The full ladder, and the four internal states a three-outcome provider maps onto:
**`references/idempotency.md`**.

## Money

Signed `BIGINT` minor units end to end. No float, no `DECIMAL` arithmetic in PHP, no `round()`.
Split with `Allocator::largestRemainder()`; the platform absorbs the top-level floor. The invariant
`platform + Σ shares === gross` holds for every period.

Rules, order of operations and rounding: **`references/money.md`**.

## When a layer may be skipped

Structure that earns nothing is a cost, and `docs/PLAN.md` grades judgment. Legitimate skips:

| Situation | Allowed |
| --- | --- |
| Pure read for display (a list, a detail page) | Component or Filament resource → Service. No DTO, no Action |
| Action needs one scalar | A typed parameter instead of a DTO — but only one, and never an array |
| One-line lookup a Service would only forward | Service method may be a one-liner; the Action still must not query |

Never skipped: anything that creates, moves, reserves, settles or reverses money goes through the
full chain, and the arch tests enforce it.

If a feature genuinely makes a layer pure overhead, that is an architecture decision — escalate to
the `architect` agent, which records it as a numbered `R-n` refinement in
`docs/features/README.md`. Do not bypass the chain silently.

## Before you finish

- `vendor/bin/pint --dirty --format agent`
- The feature's tests, and `tests/Feature/ArchTest.php`
- `php artisan ledger:verify` green, from F03 onward
