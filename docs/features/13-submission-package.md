# F13 — Documentation, Video & Submission

> **Day:** 7 — with notes collected **daily from Day 1**
> **Depends on:** all · **Plan refs:** §15, §17, §18, §19
> **Grade areas:** Documentation (5%), Video & AI transparency (5%) — and the lens through which
> reviewers see the other 90%

## Goal

Make the reasoning as visible as the code. A reviewer should understand every decision without
running anything, and be able to run everything from the README alone.

## `README.md`

Replace the Laravel default README entirely.

- What this is — one paragraph
- Requirements: PHP 8.2, MySQL 8, Redis, Node (Vite)
- Setup: clone → `composer install` → `npm install && npm run build` → `.env` → create both
  databases (app + testing) → `migrate --seed` → queue worker → scheduler
- Demo credentials (admin, student)
- Running tests: default suite, `--group=concurrency`, chaos/soak groups
- Command reference: `ledger:accrue`, `ledger:verify`, `payouts:run`, `payouts:reconcile`,
  `payments:reconcile`, `refunds:issue`
- **Assumptions** (below) and **why tests use MySQL**
- Video link and test evidence screenshot

### Assumptions to list

Single currency (EGP) · one up-front payment per subscription · engagement is pre-aggregated and
seeded · one platform-wide revenue share · UTC day boundaries · inbound refunds always succeed at
the provider · monthly payout schedule · minimum payout threshold · 7-day hold.

## `docs/ARCHITECTURE.md`

Required headings, and where each one's content already lives:

| Required heading | Source |
|---|---|
| Key architectural decisions | PLAN §4 (D‑1 … D‑11) + refinements in `features/README.md` |
| Revenue allocation strategy | D‑1 … D‑5, F04, F05 |
| Idempotency approach | PLAN §9, F03, F06, F07 |
| Provider timeout handling | D‑8, F07, F08 |
| Scaling considerations | PLAN §12, F05 scale note, `ScaleSeeder` timings |
| Known limitations | PLAN §20 + limitations called out in feature files |
| *Senior bonus (discussion)* | PLAN §19 |

Include the payout state machine and the ledger entry-pattern table.

## `docs/AI_USAGE.md`

The brief's six points: how AI was used · main prompts/workflows · generated vs. designed ·
decisions you personally made · what differentiates the solution · trade-offs chosen.

**Source: the decision log kept daily** — not memory on Day 7.

Candidate "suggestions rejected" to watch for while building — **include only the ones that
actually happened**; reviewers will probe live, and an invented rejection is easy to expose:
recognize at payment time · a mutable `balance` column · a Redis lock as the only idempotency
guard · timeout treated as failure · SQLite for tests · float or DECIMAL-in-PHP money ·
a library allocator vs. your own tie-break rule.

Decide whether `docs/PLAN.md` and `docs/features/` stay in the repository. Keeping them is honest
evidence of the planning workflow and supports this document; if you keep them, say so here.

## Video (15–20 min)

Segment plan: PLAN §17.

**Preparation:**

- A reset command sequence: `migrate:fresh --seed --seeder=DemoSeeder`
- Two terminals + Filament in a browser
- **Deterministic demos:** drive `ScriptedMockProvider` from an env value for recording (e.g.
  `PAYOUT_PROVIDER=scripted`, `PAYOUT_SCRIPT=timeout_then_success`). A random provider makes a
  live demo flaky exactly when it matters.
- Rehearse with a timer; the student flow gets ~60s.

## Repository hygiene

- One commit (or a small series) per feature with meaningful messages — the history is evidence
  of ownership and supports `AI_USAGE.md`
- `.env.example` updated with every `revenue.*` key; no `.env` committed
- `pint` run; no dead code; no leftover scaffolding

## Final pre-submit checklist

- [ ] Fresh clone into a new folder → README setup works start to finish
- [ ] Full suite green; chaos test green
- [ ] `ledger:verify` green after seeding
- [ ] `docs/ARCHITECTURE.md` and `docs/AI_USAGE.md` at exactly those paths
- [ ] Test screenshot committed
- [ ] Video link works when logged out (unlisted, not private)
