---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations: minor units, unique constraints, no nullable key columns
Money is `bigInteger()` signed minor units plus an explicit `char('currency', 3)`. Never `decimal`, never `float`.

Every "must happen once" fact gets a `UNIQUE` index — that is where idempotency is enforced, so add the constraint in the same migration as the table.

Columns that participate in a unique index are `NOT NULL` with a `0` sentinel for singletons. MySQL treats NULLs as distinct, so one nullable key column silently disables the whole guarantee.

Target MySQL 8 (the test database is MySQL, not SQLite). Generated columns, `FOR UPDATE` and unique-violation behaviour are all relied upon.

## Timestamps are Laravel's, and the application stamps them
Use `$table->timestamps()`, or a bare nullable `created_at` when the model sets `UPDATED_AT = null`. Never `useCurrent()` or `useCurrentOnUpdate()` (R50): a database-side default is a second clock, and `travelTo` cannot reach it.

Most of these tables are written by raw `insertOrIgnore` and conditional `UPDATE`, which fire no model events — so the Service that owns the table sets `created_at` / `updated_at` explicitly with `CarbonImmutable::now()`, including in the `incrementEach` extras. A forgotten stamp writes a silent null, so add it in the same change as the write.
