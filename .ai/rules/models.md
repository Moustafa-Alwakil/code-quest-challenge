---
paths:
  - 'app/Models/**'
---

# Models

## Models stay thin: relationships, casts, scopes
Relationships, a `casts()` method, query scopes and genuine model-level invariants only. No application workflows, no allocation or rounding maths, no ledger writes, no provider calls, no job dispatching — those live in Actions and Services.

Money columns cast to `int`. `LedgerEntry` is append-only: never add an `update()` or `delete()` path to it, and never a mutator that rewrites an amount.

## Dates the application reads are cast immutable
Any column the code treats as a date — a period or term boundary, a capture, a hold maturity, a settlement — is cast `immutable_date` / `immutable_datetime`, never `date` / `datetime`, and annotated `@property CarbonImmutable`. PHPStan level 10 trusts that annotation, so an uncast attribute lets `$period->period_start->addMonths(3)` mutate a model in place with nothing reported.

There is no global `Date::use(CarbonImmutable::class)`: framework defaults stay untouched (R49). Bookkeeping timestamps are left alone with them — nothing reads `created_at` or `updated_at` as a date, so they keep Laravel's own `Illuminate\Support\Carbon` and are annotated as such. `payout_items.created_at` is the exception, because F08's stranded sweep measures from it (R35).
