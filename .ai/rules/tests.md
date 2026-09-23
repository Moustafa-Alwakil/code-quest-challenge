---
paths:
  - 'tests/**'
---

# Tests

## Tests run on MySQL and assert the ledger invariants
Pest 4 against MySQL, never SQLite — unique-violation behaviour, `FOR UPDATE`, generated columns and isolation all differ, and they are exactly what is under test.

`RefreshDatabase` by default. Concurrency tests must not be transaction-wrapped: use `DatabaseTruncation` in the `concurrency` group, with two named connections interleaved by hand.

Money-touching test files register `assertLedgerBalanced()` in `afterEach` so every test implicitly proves invariants I1–I8 (see `docs/features/12-testing-and-invariants.md`).

Bind `ScriptedMockProvider` for deterministic outcomes; its transfer count per idempotency key is the source of truth for "the money moved once". Use `travelTo` for hold periods and backoff ladders, and seed randomness from `TEST_SEED`.

Read the `ledger-testing` skill before adding tests.
