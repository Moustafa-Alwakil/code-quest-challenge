# F01 — Money & Allocation Primitives

> **Day:** 1 · **Depends on:** — · **Implements:** D‑4, D‑5 · **Plan refs:** §4, §6
> **Grade areas:** Correctness of money flows (25%)

## Goal

Pure, framework-free building blocks that make it impossible to lose or invent a piastre.
Every other feature does money arithmetic through these — never inline.

## Scope

**In:** `Money` value object · `Allocator` (largest remainder) · `RevenueSplit` (platform cut) ·
`config/revenue.php`.

**Out:** currency conversion, locale formatting beyond EGP display, persistence.

## Components

| Component | Responsibility |
|---|---|
| `App\Support\Money` | Immutable `(int minor, string currency)`. Add, subtract, negate, isZero, isNegative, equals. Throws on currency mismatch. Display formatting only at the UI edge. |
| `App\Support\Allocator::largestRemainder(total, weights)` | Split a non-negative integer total across integer weights so the parts sum **exactly** to the total. Keys preserved. |
| `App\Support\RevenueSplit::split(gross, shareBps)` | Returns `[instructorPool, platformCut]`. Pool is floored; platform absorbs the sub-unit. |
| `config/revenue.php` | `instructor_share_bps`, `hold_days`, `minimum_payout_minor`, `zero_engagement_policy`, `currency`, `payout_provider`, `charge_provider`, provider probabilities. Every policy decision in the plan is a visible dial here. |

None of the three classes imports anything from `Illuminate\` — enforced by an arch test (F12).

## Rules

### Largest-remainder algorithm

1. **Validate.** Total ≥ 0. Every weight is an integer ≥ 0. At least one weight > 0 — otherwise
   throw `ZeroWeightException`. The allocator does not decide what zero engagement means; the
   caller does (D‑3, F05).
2. `W = Σ weights`. For each key: `base = floor(total × w / W)`, `rem = (total × w) mod W`.
3. `leftover = total − Σ base`. Always smaller than the number of non-zero weights.
4. Sort keys by `rem` descending, then **key ascending** (the deterministic tie-break).
5. Give +1 to the first `leftover` keys.
6. Return every input key. Callers skip zero amounts when writing rows.

Integer operations only — no float division anywhere in the path.

**Overflow bound.** `total × w` must fit in a signed 64-bit integer. Guard: total ≤ 10¹² minor
units (EGP 10bn), weight ≤ 10⁶. Product ≤ 10¹⁸ < 9.2 × 10¹⁸. Exceeding the guard throws.

### Platform cut

- `shareBps` is an integer 0 … 10 000.
- `pool = floor(gross × shareBps / 10 000)`; `platform = gross − pool`.
- Invariant: `platform + pool === gross`.

### Money construction

- From the database: `BIGINT` → PHP `int` (64-bit). Never through float.
- From human input (seeders, UI): parse a **string** (`"123.45"` → 12345). No `floatval`.
- `declare(strict_types=1)` everywhere so a float passed by accident is a `TypeError`, not a
  silent truncation.

### Initial config values (illustrative — dials, not doctrine)

| Key | Value | Meaning |
|---|---|---|
| `instructor_share_bps` | 7000 | 70% of recognized revenue goes to the instructor pool |
| `hold_days` | 7 | D‑6 |
| `minimum_payout_minor` | 10000 | EGP 100 |
| `zero_engagement_policy` | `platform_retains` | D‑3 |
| `currency` | `EGP` | single currency |

## Edge cases

| Input | Expected |
|---|---|
| total 0 | every key 0 |
| total 1 across weights 3/3/3 | lowest key gets 1, others 0 |
| single key | gets the whole total |
| weights 1 and 1 000 000 | small weight usually 0; sum still exact |
| all weights 0 | `ZeroWeightException` |
| negative total or weight | `InvalidArgumentException` |
| non-sequential keys (instructor ids 7, 42, 3) | preserved; tie-break by key ascending |
| share 0 bps / 10 000 bps | pool 0 / platform 0 |

## Acceptance criteria

- [ ] `Σ output === total` for every input — property test, ≥ 1 000 random cases, fixed seed
- [ ] Same input → identical output across repeated calls
- [ ] 1000 across 3/3/3 → 334 / 333 / 333 (tie-break demonstrated)
- [ ] 100 across seven equal weights → 15, 15, 14, 14, 14, 14, 14
- [ ] `platform + pool === gross` across a fuzzed range of gross × bps
- [ ] 100% line coverage on these three classes — cheap here, and this is where it matters

## Tests (unit, no database)

- Allocator: hand-picked datasets, property test, degenerate inputs, key preservation, determinism
- RevenueSplit: bounds, fuzz, invariant
- Money: currency mismatch throws, immutability, string parsing, negative handling

## Demo hook

Video scenario 7 (rounding edge cases): run the Pest dataset live — 1000 / [3,3,3] and
100 / [1×7] — and show the sum assertion.

## Notes

**Alternative considered:** `brick/money` has an `allocate()` that does a similar job. Writing
~60 lines yourself is justified here because the tie-break rule is a business decision you must
own and explain on camera; a library's rule is a rule you inherited. Either is defensible — this
is a good candidate for the *"AI suggested X, I chose Y"* section of `AI_USAGE.md` if it comes up.
