---
paths:
  - 'app/Services/**'
---

# Services

## Services own persistence; group per aggregate, not per Action
This is the only layer allowed to touch Eloquent models, the query builder and `DB`. Group per aggregate (`LedgerService`, `InstructorBalanceService`, `AccrualService`, `PayoutRunService`, `RefundService`) — never one Service per Action, and never a pass-through method that only forwards to `Model::create()`.

Services do not open transactions (the calling Action does) and do not dispatch jobs or fire UI concerns. A Service that needs a transaction to already be open asserts it — `if (DB::transactionLevel() === 0) { throw … }` — rather than opening one itself.

A Service may call another Service, but only to hold one invariant together, and only downward through an acyclic graph (R18). `LedgerService::post()` calling `InstructorBalanceService::applyDeltas()` is the sanctioned shape: "apply the deltas *only if* the legs were inserted" is a rule that must exist in exactly one place, not be re-obeyed by every Action. This is not a licence for general Service-to-Service wiring — if the second call is merely convenient, the Action composes the two itself.

At scale the rules are strict: keyset pagination (`WHERE id > ?`) and `lazyById()`, never `OFFSET` or `->get()` on a large set; chunked `insertOrIgnore` rather than per-row saves; atomic `UPDATE … SET x = x + ?` rather than read-modify-write in PHP.
