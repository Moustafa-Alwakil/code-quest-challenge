---
paths:
  - 'app/**'
---

# App

## Layering: entry point → DTO → Action → Service → Model
Every meaningful operation flows: entry point (Livewire component, Artisan command, queued job, Filament page) → DTO → invokable Action → Service → Model. Do not let an entry point reach a Model or `DB` directly for anything that creates, moves or reverses money.

This overrides Boost's `laravel-best-practices/rules/architecture.md`: Actions here are `final`, invokable, and take a DTO — `__invoke(RecognizePeriodData $data)`, never `handle(array $data)`.

Read the `revenue-ledger-architecture` skill before adding a class in any of these layers.

## Money is integer minor units, and correctness lives in the database
All amounts are signed `BIGINT` piastres (EGP ×100) carried as PHP `int` with an explicit currency. No float, no `DECIMAL`-to-string maths, no `round()` on money, anywhere. Arithmetic goes through `App\Support\Money`; splitting goes through `App\Support\Allocator::largestRemainder()`.

Idempotency is enforced by `UNIQUE` indexes and conditional `UPDATE … WHERE status = ?` (assert `affected === 1`). `Cache::lock`, `ShouldBeUnique` and `WithoutOverlapping` are optimizations that save wasted work — never write code whose correctness depends on them.
