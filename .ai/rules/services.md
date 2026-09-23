---
paths:
  - 'app/Services/**'
---

# Services

## Services own persistence; group per aggregate, not per Action
This is the only layer allowed to touch Eloquent models, the query builder and `DB`. Group per aggregate (`LedgerService`, `InstructorBalanceService`, `AccrualService`, `PayoutRunService`, `RefundService`) — never one Service per Action, and never a pass-through method that only forwards to `Model::create()`.

Services do not open transactions (the calling Action does) and do not dispatch jobs or fire UI concerns.

At scale the rules are strict: keyset pagination (`WHERE id > ?`) and `lazyById()`, never `OFFSET` or `->get()` on a large set; chunked `insertOrIgnore` rather than per-row saves; atomic `UPDATE … SET x = x + ?` rather than read-modify-write in PHP.
