---
paths:
  - 'app/Filament/**'
---

# Filament

## Filament admin is read-only
Resources are read-only: `canCreate()`, `canEdit()` and `canDelete()` return `false`. The admin panel observes the ledger; it never writes to it.

No `DB` and no raw `LedgerEntry` aggregation in a resource or widget — read the `instructor_balances` snapshot, or go through a Service. Any operator action that would move money must be an Action invoked from a Filament action, with the same DTO contract as everywhere else.
