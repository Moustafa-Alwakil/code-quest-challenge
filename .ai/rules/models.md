---
paths:
  - 'app/Models/**'
---

# Models

## Models stay thin: relationships, casts, scopes
Relationships, a `casts()` method, query scopes and genuine model-level invariants only. No application workflows, no allocation or rounding maths, no ledger writes, no provider calls, no job dispatching — those live in Actions and Services.

Money columns cast to `int`. `LedgerEntry` is append-only: never add an `update()` or `delete()` path to it, and never a mutator that rewrites an amount.
