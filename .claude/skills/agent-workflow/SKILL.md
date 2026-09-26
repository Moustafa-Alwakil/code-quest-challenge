---
name: agent-workflow
description: "How to route work through this project's four sub-agents — researcher, architect, developer, tester — and the structured handoff schema they exchange. Use when starting a feature from docs/features/, when deciding whether a task needs research or architecture before implementation, when a developer hits a boundary decision that needs escalation, or when coordinating a multi-stage change. Covers the routing table, the stages that should be skipped, the handoff format, and the review loop. Do not use for the architecture rules themselves (see revenue-ledger-architecture)."
---

# Agent Workflow

Four agents: `researcher`, `architect`, `developer`, `tester` (defined in `.claude/agents/`).
The full chain is **Research → Architecture → Development → Testing → Review**.

## Route by what is actually unknown

Most tasks do not need four agents. Running every task through the full chain is the failure mode
this skill exists to prevent — it burns a week's budget on ceremony and each cold agent re-derives
context the last one already had.

| The task | Route |
| --- | --- |
| A rename, a typo, one more assertion, a copy change, a config value | **Do it yourself.** No agent. |
| Well-specified by a `docs/features/NN-*.md` file, shape matches existing code | `developer` → `tester` |
| Specified, but touches a failure path, a state machine or the ledger | `developer` → `tester` → `architect` (review) |
| A framework or provider behaviour genuinely needs verifying | `researcher` → `developer` → `tester` |
| A new boundary, an ambiguous doc, or a `D‑n` decision in question | full chain |

**The three triggers that actually warrant the full chain:**

1. A new class layer, a new Service, or a boundary the architecture does not already settle.
2. The `docs/features/` file is silent or self-contradictory on something that changes the design.
3. The work would deviate from a numbered `D‑n` decision or `R‑n` refinement.

Nothing else. "This feature feels important" is not a trigger — F03 and F06 are the most important
features in the submission and both are fully specified.

## Stage contracts

| Stage | Agent | Produces | Must not |
| --- | --- | --- | --- |
| Research | `researcher` | Findings, verified separately from assumed | Edit any file |
| Architecture | `architect` | Boundary design, `R‑n` refinement if deviating | Write feature code |
| Development | `developer` | Implementation **and** its tests, Pint clean | Change a boundary without escalating |
| Testing | `tester` | Verdict on correctness, not just green tests | Redesign or rewrite feature code |
| Review | `architect` | Accept, or a list of deviations to fix | Re-plan work that already matches the design |

Development and testing are not sequential phases of the calendar: the `developer` writes tests
alongside the implementation. The `tester` is an independent check afterwards, not the first person
to think about testing.

## The loop

```
developer → tester
              ├── correct        → done
              ├── defects        → developer fixes → tester re-verifies
              └── architectural  → architect decides → developer → tester
                   deviation
```

Cap it at two rounds. A third round means the design was wrong, not the code — go back to the
`architect` rather than iterating on symptoms.

## Handoff schema

Every agent reports in this shape. Omit a heading only when it is genuinely empty; never silently
drop **Assumptions**, **Risks** or **Open questions**, since those are the ones that cost money
when they go unsaid.

```markdown
## Requirement
## Understanding
## Existing patterns discovered
## Proposed changes
## Architectural decisions
## Files / components affected
## Assumptions
## Risks
## Testing requirements
## Open questions
```

## Passing context

A fresh agent starts cold. Give it, every time:

- The `docs/features/NN-*.md` file for the work.
- The `D‑n` decisions and `R‑n` refinements that govern it.
- The preceding stage's handoff report, verbatim.
- Concrete `file:line` references already found — never make it re-discover them.

Continue an existing agent with `SendMessage` rather than spawning a second one of the same kind;
its context is worth more than a clean start.

## Every agent, regardless of stage

1. Read `.ai/rules/index.md`, then every rule file whose globs cover the paths in scope, then
   `grep -rin '<keyword>' .ai/rules`. Before planning, before editing.
2. Load the skills its definition names. `revenue-ledger-architecture` governs all application code.
3. Prefer Boost MCP tools — `search-docs`, `database-schema`, `database-query`, `list-artisan-commands`
   — over shell guesswork.
4. `vendor/bin/pint --dirty --format agent` after touching PHP.
5. Report what actually happened. A failing test is reported with its output, not summarized away.
