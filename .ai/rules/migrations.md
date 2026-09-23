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
