---
name: feature-research
description: "Investigation protocol for this Instructor Revenue Ledger codebase, for the researcher agent and for anyone who needs facts before designing. Use when a requirement is unclear, when a package's or framework's real behaviour must be verified, when comparing implementation alternatives, or when you need to know what patterns already exist before introducing a new one. Covers the source-of-truth order (feature docs → existing code → Boost skills → search-docs → semantic search), version verification, and a findings template that separates verified fact from assumption. Do not use for writing implementation code or for designing the architecture (see feature-architecture)."
---

# Feature Research

Research produces **evidence an architect can act on**, not opinions. The deliverable is a findings
report, never a code change.

## Source-of-truth order

Work down this list. Stop when the question is answered; do not skip a step to reach a more
convenient source.

1. **`docs/features/NN-*.md`** — the feature specification. It already names the tables, the
   constraints, the components, the edge cases and the acceptance criteria. Most "open questions"
   are answered here, and reading it first is what stops you inventing requirements.
2. **`docs/PLAN.md`** — the reasoning. Decisions are numbered `D‑1 … D‑11` with the alternatives
   that were rejected and why. If a proposal contradicts a `D‑n`, that is the headline finding.
3. **`docs/features/README.md`** — the refinements table (`R1 … R17`). It supersedes `PLAN.md`
   where the two differ; `PLAN.md` is deliberately left unedited.
4. **`.ai/rules/`** — start at `index.md`, read every file whose globs cover the paths in scope,
   then `grep -rin '<keyword>' .ai/rules` for what a path match misses.
5. **The existing codebase** — an established pattern beats a better idea. Read the sibling files.
6. **Laravel Boost skills** in `.claude/skills/` — `laravel-best-practices` (18 rule files),
   `testing-best-practices` (9), `livewire-development`, `tailwindcss-development`.
7. **`search-docs`** (Boost MCP) — official Laravel / Livewire / Filament / Pest documentation,
   version-matched to what is installed. Use topic queries, scoped with a `packages` array.
8. **`jbcontext search`** or the `context-search` skill — semantic search when you do not know where
   something lives. One query, then read the files it returns.

## Verify, never assume

- **Versions.** `composer show --direct` and `package.json`. Currently: Laravel 11.56, Livewire 3.8,
  Filament 3.3, Pest 4.7, PHP 8.4. Never reason from a major version you have not confirmed.
- **Schema.** `database-schema` (Boost MCP) before proposing a migration; `database-query` for
  read-only checks. Do not describe a column from memory.
- **Routes and commands.** `php artisan route:list`, `php artisan list`, `php artisan <cmd> --help`.
- **Behaviour you are unsure of.** Find it in the vendor source and cite `file:line`. A citation
  ends an argument; a recollection starts one.

## Questions worth researching here

This codebase has a narrow set of genuinely hard questions. Aim at them:

- Does MySQL 8 actually behave this way under concurrency — unique-violation timing, `FOR UPDATE`
  scope, generated columns, `insertOrIgnore` and its return value?
- What does the framework guarantee about transaction boundaries, `afterCommit()`, job retries,
  `ShouldBeUnique` TTLs, and batch `finally` callbacks when a worker is killed?
- Does an approach hold at 500k subscriptions and tens of millions of rows, or only in a test?
- Which of `D‑1 … D‑11` does this touch, and does the proposal quietly contradict one?

## Output template

```markdown
## Requirement
What was asked, in one or two sentences, with the `docs/features/` file it maps to.

## Understanding
The restated problem, and which `D‑n` decisions and `R‑n` refinements govern it.

## Existing patterns discovered
- `path/to/File.php:123` — what it does and why it is relevant

## Verified facts
- Claim — evidence (`file:line`, `search-docs` result, command output)

## Assumptions
- Assumption — why it could not be verified, and what would confirm it

## Alternatives considered
| Option | Cost | Risk | Fits the architecture? |

## Risks and edge cases
- Each with the failure it would cause

## Open questions for the Architect
- Questions only, no guesses
```

## Rules

- **Never edit a file.** Research is read-only; that is what makes it fast and safe to run first.
- **Never blur verified and assumed.** Two headings, always, even when one is empty.
- **Report a contradiction, do not resolve it.** If a finding conflicts with a `D‑n` decision, say
  so and hand it to the Architect. Decisions are the user's to change.
- **Cite or drop it.** A finding without a `file:line`, a doc result or a command output is an
  opinion, and opinions do not belong in a findings report.
