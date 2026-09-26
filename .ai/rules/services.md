---
paths:
  - 'app/Services/**'
---

# Services

## Services own persistence; group per aggregate, not per Action
This is the only layer allowed to touch Eloquent models, the query builder and `DB`. Group per aggregate (`LedgerService`, `InstructorBalanceService`, `AccrualService`, `PayoutRunService`, `RefundService`) — never one Service per Action, and never a pass-through method that only forwards to `Model::create()`.

Services do not open transactions (the calling Action does) and do not dispatch jobs or fire UI concerns. A Service that needs a transaction to already be open asserts it — `if (DB::transactionLevel() === 0) { throw … }` — rather than opening one itself.

That assert belongs to a Service guarding **its own** multi-write invariant, not to every write method an Action happens to compose. `LedgerService::post()` earns it: the legs and their balance deltas are one indivisible fact (R18), so being called outside a transaction is a bug in the caller that the Service can detect. A single-statement method — `AccrualService::scheduleFor()`, `SubscriptionService::recordPayment()` — does not, because the Action's boundary is already the thing that makes it atomic and the guard would only repeat, three times over, a rule that has one owner.

A Service may call another Service, but only to hold one invariant together, and only downward through an acyclic graph (R18). `LedgerService::post()` calling `InstructorBalanceService::applyDeltas()` is the sanctioned shape: "apply the deltas *only if* the legs were inserted" is a rule that must exist in exactly one place, not be re-obeyed by every Action. This is not a licence for general Service-to-Service wiring — if the second call is merely convenient, the Action composes the two itself.

A **read-only** downward call is admitted on a weaker test (R29). `InstructorBalanceService` injects `EarningAllocationService` because `held` is defined by allocation rows and nothing else can answer for it (R2), and allocations know nothing about balances, so the graph stays acyclic. R18's "only to keep one invariant atomic" governs *writes*, where a missed call is silent drift; a read has no such failure mode. It is still not general wiring: the test is that the second Service owns the definition of what the first is returning.

At scale the rules are strict: keyset pagination (`WHERE id > ?`) and `lazyById()`, never `OFFSET` or `->get()` on a large set; chunked `insertOrIgnore` rather than per-row saves; atomic `UPDATE … SET x = x + ?` rather than read-modify-write in PHP.

"Chunked" means *never loop `save()`* — one multi-row `insertOrIgnore` is the stronger form of the rule, not a violation of it. Do not wrap a list whose length is bounded by a constant (an accrual term is at most 12 rows) in an `array_chunk` loop whose second iteration is unreachable: that is a branch no test can take. Chunk where the row count is genuinely unbounded — across subscriptions, not within one.
