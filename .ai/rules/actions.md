---
paths:
  - 'app/Actions/**'
---

# Actions

## Actions: final, invokable, one use case, no Eloquent
`final class ReserveInstructorBalanceAction` with a single `__invoke(SomeData $data): Result`. Name the concrete operation (`ApplyProrataRefundAction`), never a noun bucket (`PayoutAction`, `LedgerAction`).

An Action orchestrates one use case and owns the transaction boundary — `DB::transaction(...)` lives here, not in a Service. It must not query: no Eloquent, no query builder, no `DB::table()`. Inject Services via constructor promotion and delegate every read and write.

Ordering rule for anything touching a provider: commit the intent, call the provider outside the transaction, then commit the outcome. Never hold a transaction open across a network call.
