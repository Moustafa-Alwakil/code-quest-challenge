---
name: feature-architecture
description: "Turns a requirement into an implementation design for this Instructor Revenue Ledger codebase, for the architect agent and for anyone deciding where logic belongs before writing it. Use when defining Livewire component, Action, DTO, Service and Model boundaries, when judging whether a new class or layer earns its keep, when reviewing an implementation for separation-of-concerns violations, or when a change would deviate from a numbered D-n decision or R-n refinement. Covers the boundary decision tables, the design output contract, and the obligation to record deviations. Do not use for writing feature code (see revenue-ledger-architecture) or for gathering facts (see feature-research)."
---

# Feature Architecture

The job is to decide **boundaries** — which classes exist, what each one is responsible for, and
what crosses between them — and to write that down precisely enough that a developer implements it
without re-deciding anything. Not to write the feature.

Read `revenue-ledger-architecture` first; it is the contract this skill applies. Read the
`docs/features/NN-*.md` file for the work in hand; it is the requirement.

## The bias

`docs/PLAN.md` §1 is explicit: *"A smaller solution with strong engineering judgment will score
higher than a larger solution with weak reasoning."* Abstraction that earns nothing is a defect
here, exactly like a missing constraint. Every class you add must be answerable to "what would
break if this were one layer up?"

At the same time, the money core is graded on correctness and failure handling. Where a boundary
makes an invariant provable or a failure path testable, it earns its keep immediately.

## Decision tables

### Does this need an Action?

| Signal | Verdict |
| --- | --- |
| It creates, moves, reserves, settles or reverses money | **Action, always** |
| It changes a state machine status | **Action** |
| It is a use case a reviewer would name in a sentence ("approve", "cancel", "reconcile") | **Action** |
| Two entry points need the same operation | **Action** |
| It is a read for display | No — component or resource → Service |
| It is one `where()` a Service method already covers | No |

### Does this need its own Service?

| Signal | Verdict |
| --- | --- |
| The aggregate has no Service yet | **Yes** |
| Several Actions need the same query or write | **Yes** |
| The query is non-trivial: keyset pagination, `lockForUpdate`, chunked `insertOrIgnore`, atomic increment | **Yes** — and it must not live in an Action |
| It would be the seventh Service | Check first whether a method on an existing one is the honest answer |
| It would only forward one call to `Model::create()` | Still yes if an Action needs it — Actions never query — but keep it a one-liner and put it on the aggregate's existing Service |

Expected set: `AccrualService`, `LedgerService`, `InstructorBalanceService`, `PayoutRunService`,
`SubscriptionService`, `RefundService`. A seventh needs a reason.

### Does this need a DTO?

| Signal | Verdict |
| --- | --- |
| The Action takes two or more inputs | **DTO** |
| Inputs include policy values from `config/revenue.php` | **DTO**, populated by a named constructor |
| Two entry points build the same input | **DTO** |
| Exactly one scalar (`$payoutItemId`) | A typed parameter is acceptable |
| An array | **Never.** An array parameter is the thing DTOs exist to eliminate |

### Where does the transaction boundary go?

Always the Action. If a design needs two Services to commit atomically, that is one Action wrapping
both. If a design needs a provider call in the middle, that is **two** commits with the call between
them — commit the intent, call, commit the outcome — never one transaction spanning the call.

## Design output contract

```markdown
## Requirement
The feature, and its `docs/features/NN-*.md` file.

## Understanding
The restated problem and the `D‑n` / `R‑n` decisions that govern it.

## Existing patterns reused
- `path/to/File.php` — what is being extended rather than reinvented

## Proposed structure
| Layer | Class | Responsibility |
| --- | --- | --- |
| Entry | `App\Livewire\Checkout` | … |
| DTO | `App\DTOs\Checkout\StartCheckoutData` | fields and types |
| Action | `App\Actions\Checkout\StartCheckoutAction` | the use case, the transaction boundary |
| Service | `App\Services\SubscriptionService` | methods added |
| Model | `App\Models\Payment` | columns, casts, relationships |

## Architectural decisions
Each with the alternative rejected and why.

## Transaction boundaries and ordering
Where the commits are; where any provider call sits relative to them.

## Idempotency
Which unique constraint or CAS protects each "must happen once" fact.

## Files affected
Created / modified, by path.

## Assumptions
## Risks
## Testing requirements
The behaviours the Tester must prove, including the failure paths and the invariants touched.

## Open questions
```

## Deviations are recorded, never silent

If the design skips a layer, adds one, or contradicts a `D‑n` decision, append a numbered `R‑n` row
to the *Refinements to PLAN.md* table in `docs/features/README.md` — the repo's existing mechanism
(`R1 … R17` are already there). State the refinement, where it applies, and why.

`docs/PLAN.md` is left unedited by design. Do not modify it.

A deviation that touches a `D‑n` decision is the user's call, not yours: raise it, do not absorb it.

## Reviewing an implementation

Look for exactly these, in order. They are the violations this architecture is shaped to prevent:

1. An entry point reaching a Model or `DB` directly for a money operation.
2. An Action running a query, or holding a transaction across a network call.
3. A Service opening a transaction, dispatching a job, or firing a notification.
4. Business logic accumulating in a Livewire component method.
5. A lock standing where a unique constraint should be.
6. A float, a `round()`, or a `DECIMAL` touching money.
7. A god-class Action — a noun name, or a second public method.
8. An abstraction with exactly one caller and no test benefit. Say so plainly and remove it.
