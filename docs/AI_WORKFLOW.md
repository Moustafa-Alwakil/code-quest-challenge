# AI Development Workflow

> How this repository is built with AI assistance. Set up on **Day 0**, before `PLAN.md` §16 Day 1.
> This is the raw material for `docs/AI_USAGE.md` (F13) and the AI-transparency segment of the video.

The brief allows AI and requires disclosure, and warns that the review will ask the candidate to
explain and *modify the implementation live*. So the workflow is part of the submission, not a
private convenience. Everything described here is committed and inspectable.

---

## 1. The problem this solves

`PLAN.md` and the thirteen files in `docs/features/` are a complete, decision-logged design. What
they could not do is constrain *how* an AI agent turns a feature file into code. Left alone, a
capable model reliably produces the same four defects on a system like this:

1. Business logic accumulating inside Livewire components.
2. Money maths and workflows on Eloquent models.
3. Actions taking arrays, so the contract lives nowhere.
4. A Redis lock mistaken for an idempotency guarantee.

Those are exactly the four things this submission is graded on avoiding. So the environment is
built to make the correct shape the path of least resistance, and — where it matters — to make the
incorrect shape a failing test.

---

## 2. The architecture the environment enforces

```
Entry point            Livewire component · Artisan command · queued job · Filament page
      ↓                validate, authorize, build the input contract
    DTO                final readonly, scalars only
      ↓
   Action              one use case, invokable, owns the transaction boundary
      ↓
  Service              the only layer that touches Eloquent and DB
      ↓
   Model               relationships, casts, scopes
```

`App\Support` (`Money`, `Allocator`, `RevenueSplit`) sits beside the chain: pure functions, no
framework, no I/O.

Recorded as refinements **R13–R17** in `docs/features/README.md`. `PLAN.md` is left unedited, per
its own convention.

**Why the entry point is generalized.** The brief for this workflow described the chain as starting
at Livewire. But roughly 65% of the grade enters the system through Artisan commands and queued
jobs, and the only genuinely Livewire feature (F11) is discretionary and may be cut entirely. A
Livewire-only rule would have governed the least important code in the repository. Generalizing it
covers 100% of the application while leaving Livewire its own layer of guidance on top.

---

## 3. Layers of guidance, and which wins

Four mechanisms, deliberately different in weight:

| Mechanism | Where | Weight |
|---|---|---|
| **Rules** | `.ai/rules/` | Short, path-scoped, read before any edit |
| **Skills** | `.claude/skills/` | Deep guidance, loaded when the task matches |
| **Agents** | `.claude/agents/` | Role, tool scope, escalation rule |
| **Arch tests** | `tests/Feature/ArchTest.php` | The only mechanism that actually fails |

Conflict priority, in order:

1. Explicit project requirements (the brief, `PLAN.md`, `docs/features/`)
2. Established project architecture (the chain above)
3. Existing project conventions (sibling files)
4. Laravel Boost guidance
5. General Laravel / Livewire convention

---

## 4. Laravel Boost, extended not replaced

Boost installed five skills: `laravel-best-practices` (18 rule files), `testing-best-practices` (9),
`livewire-development`, `tailwindcss-development`, `infer-conventions`. They are the baseline and
are left unmodified.

The six project skills each open with a *"What Boost already covers"* section and delegate rather
than restate. Nothing generic was rewritten — no project Laravel skill, no project Tailwind skill,
no generic testing skill.

**One deliberate override, worth naming in the video.** Boost's
`laravel-best-practices/rules/architecture.md` teaches `CreateOrderAction::handle(array $data)`.
This project uses `final` invokable Actions taking a DTO. The override is recorded explicitly in
`.ai/rules/app.md` and restated in the architecture skill, so an agent meets the contradiction with
the resolution already attached rather than picking whichever it read last.

---

## 5. What is in the repository

### `.ai/rules/` — 12 path-scoped rule files

Written with Boost's `record-rule` tool, which owns the frontmatter and regenerates `index.md` as a
glob→file table. Every agent reads the index and the matching files before planning or editing.

`app.md` (layering + money + idempotency, applies everywhere) · `actions.md` · `dtos.md` ·
`services.md` · `models.md` · `livewire.md` · `commands.md` · `jobs.md` · `filament.md` ·
`support.md` · `migrations.md` · `tests.md`

### `.claude/skills/` — 6 project skills

| Skill | Covers |
|---|---|
| `revenue-ledger-architecture` | The layering contract, naming, transaction boundaries, the idempotency ladder, integer money, when a layer may be skipped. References: `layering.md`, `idempotency.md`, `money.md` |
| `livewire-feature-development` | Component contract, validation→DTO, `#[Locked]` intent keys, the `unknown` payment state in the UI, authorization, N+1 and state size, F11 scope discipline |
| `ledger-testing` | MySQL-not-SQLite, the concurrency group, invariants I1–I8, the three required proofs, layer-by-layer test strategy, the arch tests, the chaos test |
| `feature-research` | Source-of-truth order, version and schema verification, findings template separating verified from assumed |
| `feature-architecture` | Boundary decision tables, design output contract, the obligation to record deviations as `R-n`, the review checklist |
| `agent-workflow` | Routing table, the stages to skip, the handoff schema, the review loop |

### `.claude/agents/` — 4 sub-agents

| Agent | Tool scope | Escalation |
|---|---|---|
| `researcher` | Read-only | Reports contradictions; never resolves them |
| `architect` | Read-only + writes `docs/**` | A `D-n` deviation is the user's call |
| `developer` | Full | Escalates boundary changes rather than redesigning |
| `tester` | Full, writes only `tests/**` | Never relaxes an arch test to make it pass |

---

## 6. The workflow

**Research → Architecture → Development → Testing → Review**, but routed by what is actually unknown:

| The task | Route |
|---|---|
| Rename, typo, one more assertion, a config value | No agent |
| Well-specified by a `docs/features/` file | `developer` → `tester` |
| Touches a failure path, state machine or the ledger | `developer` → `tester` → `architect` review |
| Framework or provider behaviour needs verifying | `researcher` → `developer` → `tester` |
| New boundary, ambiguous doc, or a `D-n` in question | Full chain |

Only three things trigger the full chain: a new layer or boundary the architecture does not settle;
a feature doc that is silent or self-contradictory on something design-affecting; work that would
deviate from a numbered decision or refinement.

Forcing every task through five stages is a failure mode, not thoroughness — it spends a
seven-day budget on ceremony, and each cold agent re-derives context the previous one already had.
The routing table exists to prevent that.

---

## 7. Making it real: the architecture tests

Rules and skills are advisory. An agent under pressure can ignore both, and a reviewer cannot
verify a claim about guidance.

`tests/Feature/ArchTest.php` (specified in `docs/features/12-testing-and-invariants.md`) asserts:
Actions are final, suffixed and invokable, and do not query; DTOs are `final readonly` and free of
`Illuminate`; `App\Models` is used only inside `App\Services`; entry points do not reach past
Actions into Services; `App\Support` does not use `Illuminate`; jobs implement `ShouldQueue`.

This is the single highest-value piece of the setup. It turns *"Services where they earn their
keep"* from a judgment call an agent can quietly ignore into a red test — and it means the
architecture claim in the video is demonstrable in one command.

---

## 8. Honest limitations

- **The environment was set up before the code existed.** The rules describe a decided architecture,
  not an inferred one. Boost's own `infer-conventions` skill should be run after F05 lands to catch
  drift between what the rules say and what actually got written.
- **A five-layer chain is more structure than `PLAN.md` §1's minimalism implies.** It earns its
  place because the reviewer will ask for live modifications (`PLAN.md` §18) and predictable seams
  are what make that survivable — but it is a genuine trade-off, not a free win. The skills name
  the cases where a layer may legitimately be skipped rather than pretending there are none.
- **Skills and rules are advisory by construction.** Only the arch tests bind. Anything that
  matters enough to be guaranteed should end up as a test or a database constraint, which is the
  same argument this system makes about Redis locks.

---

## 9. For `AI_USAGE.md`

Keep appending to the decision log as you build, per `PLAN.md` §18: what was accepted from AI, what
was rejected, and why. The section that differentiates the submission is **what was rejected** —
and this environment is itself an example, since the four defects listed in §1 are exactly what a
typical AI-generated submission ships.
