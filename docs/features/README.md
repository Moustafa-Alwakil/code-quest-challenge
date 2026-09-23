# Feature Breakdown — Instructor Revenue Ledger

The reasoning lives in [`../PLAN.md`](../PLAN.md), unchanged. These files split that plan into
buildable features. Each one stands alone: goal, scope, data model, components, rules, edge cases,
acceptance criteria, tests, and its hook into the video.

## Features

| # | Feature | Day | Depends on | Decisions | Required item |
|---|---|---|---|---|---|
| [F01](01-money-and-allocation.md) | Money & allocation primitives | 1 | — | D‑4, D‑5 | 2 |
| [F02](02-catalog-and-seed-data.md) | Catalog & seed data | 1 (+ after F04) | F01 | inputs for D‑2 | 1 |
| [F03](03-ledger-core.md) | Ledger core | 2 | F01 | D‑9 | 1 |
| [F04](04-subscriptions-and-accrual-schedule.md) | Subscriptions, payments & accrual schedule | 2 | F01–F03 | D‑1 | 1, 2 |
| [F05](05-revenue-recognition.md) | Revenue recognition, allocation & hold | 2 | F01–F04 | D‑1, D‑2, D‑3, D‑6 | 2 |
| [F06](06-payout-runs.md) | Payout runs & reservation | 3 | F03, F05 | D‑10 | 3 · proof #1 |
| [F07](07-payout-execution-and-provider.md) | Provider, mock & payout execution | 4 | F06 | D‑8, D‑10 | 3, 4 · proof #2 |
| [F08](08-payout-reconciliation.md) | Payout reconciliation | 4 | F07 | D‑8 | 4 · proof #3 |
| [F09](09-refunds-and-clawbacks.md) | Refunds & clawbacks | 5 | F04, F05 (F06–F08) | D‑7 | brief: refunds |
| [F10](10-filament-admin.md) | Filament admin (read-only) | 5 | F03, F06 | — | 6 |
| [F11](11-student-flow.md) | Student flow | 6 *(discretionary)* | F04, F09 | D‑11 | — |
| [F12](12-testing-and-invariants.md) | Test infrastructure, invariants & chaos | continuous | all | — | 5 |
| [F13](13-submission-package.md) | Docs, video & submission | 7 | all | — | docs, video |

## Build order

```
F01 Money ──┬──► F03 Ledger ──► F04 Subscriptions ──► F05 Recognition ──► F06 Runs ──► F07 Execution ──► F08 Reconcile
F02 Catalog ┘                        │                      │                                              │
                                     │                      └──────────────► F09 Refunds ◄────────────────┘
                                     │                                            │
                                     └──────────────────────────────► F11 Student flow (Day 6, after the cut line)

F10 Filament ← F03, F06          F12 Testing ← runs alongside everything          F13 Submission ← Day 7
```

**Cut line: end of Day 5.** F01–F10 and F12's chaos test carry every graded point. F11 is built
only if they are green.

## Decision → feature map

| Decision | Features |
|---|---|
| D‑1 Accrual over the term | F04 (schedule), F05 (recognition), F09 (why refunds are cheap) |
| D‑2 Engagement-weighted split | F05 (F02 supplies the data) |
| D‑3 Zero engagement → platform | F05 |
| D‑4 Integer minor units | F01 |
| D‑5 Largest remainder | F01, used in F04, F05, F09 |
| D‑6 Hold period | F05 (release), F09 (free clawbacks) |
| D‑7 Negative balance carry-forward | F09, F06 (skips negatives) |
| D‑8 Unknown ≠ failed | F07, F08, F11 (inbound) |
| D‑9 Append-only double-entry ledger | F03 |
| D‑10 Constraints for correctness, locks for efficiency | F03, F06, F07 |
| D‑11 Thin student flow | F11 |

## Refinements to PLAN.md

Writing each feature at build level surfaced gaps in the plan. `PLAN.md` is left as it is; the
refinements live in the feature files and are collected here so `ARCHITECTURE.md` picks them up.

| # | Refinement | Where | Why |
|---|---|---|---|
| R1 | Pro-rata refunds never claw back from instructors; clawbacks only arise from full refunds | F09 | Unused time = unrecognized periods under accrual. Sharpens PLAN §11. |
| R2 | The hold is tracked on allocation rows + a `held` snapshot field, not as ledger entries | F03, F05 | PLAN didn't say how the hold was represented; ledger entries would add tens of millions of rows for no new information |
| R3 | No `recognizing` status — the CAS and the writes share one transaction | F05 | A separate status can be stranded by a crash (PLAN §7) |
| R4 | `account_id` / `reference_id` NOT NULL; `0` for singleton accounts | F03 | MySQL unique indexes treat NULLs as distinct — a nullable key column silently disables idempotency |
| R5 | `deferred_revenue` keyed per subscription; `provider_in_transit` per instructor | F03 | Makes "each liability returns to zero" and "reserved per instructor" provable |
| R6 | Per-instructor revenue share dropped | F05 | Changes the order of rounding operations; documented as an extension |
| R7 | `payments.idempotency_key` + one-live-subscription generated column | F04, F11 | Needed for double-submit safety at checkout |
| R8 | Mock providers persist to a table | F07, F11 | "Discover the result later" must work across worker processes |
| R9 | Payout items born `reserved`; no `pending` state | F06 | Creation and reservation share a transaction |
| R10 | Period boundaries computed from `term_start + k months`, not chained | F04 | Chaining drifts (Jan 31 → Feb 28 → Mar 28 …) |
| R11 | Concurrency tests cannot use transaction-wrapped `RefreshDatabase` | F12 | The second connection can't see uncommitted rows, so the race never happens |
| R12 | Status-first on job retry; `not_found` grace window before resending | F07, F08 | Robust against providers with weaker dedup or lagging status APIs |

## Definition of done — every feature

- [ ] Migrations include every constraint the feature lists
- [ ] The feature's tests pass against MySQL
- [ ] `ledger:verify` green after the feature's tests (from F03 onward)
- [ ] One commit (or a small series) with a meaningful message
- [ ] Decision-log note added for anything AI suggested that you changed or rejected
