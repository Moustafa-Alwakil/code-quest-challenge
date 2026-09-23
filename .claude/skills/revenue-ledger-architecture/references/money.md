# Money

`docs/PLAN.md` D‑4 and D‑5, and `docs/features/01-money-and-allocation.md`.

## Representation

Signed `BIGINT` piastres (EGP ×100) with an explicit `currency` column, carried as PHP `int`.

Never `float`. Never `DECIMAL` arithmetic in PHP — MySQL stores it safely, but the value becomes a
float the moment PHP touches it. Never `round()`, `number_format()` or `/` on an amount. Integers
are exact everywhere and compare exactly in tests.

`App\Support\Money` wraps `(int $minor, string $currency)`, is immutable, and throws on
cross-currency arithmetic. Formatting happens only at the UI edge.

## Order of operations for one subscription-period

```php
$pool     = intdiv($gross * $instructorShareBps, 10_000);   // floor
$platform = $gross - $pool;                                  // platform absorbs the sub-unit
$shares   = Allocator::largestRemainder($pool, $weights);    // sum(shares) === $pool, exactly
```

Flooring the pool means the platform, not an instructor, eats the fractional piastre. A platform can
account for a rounding bucket; an instructor cannot be short-changed by an invisible remainder.

**The invariant, asserted in tests:** `platform + Σ shares === gross`, for every period, always.

## Largest remainder

Each instructor gets `floor(pool × wᵢ / W)`. The leftover `pool − Σ floor(...)` piastres go out one
each, in order of descending fractional remainder, tie-broken by **ascending instructor id**.

Three guarantees at once: the parts sum to the whole exactly; no instructor is systematically
favoured; the result is byte-identical on every re-run — which is what makes allocation both
idempotent and testable.

Worked example: pool 1000, weights 3/3/3 → floors 333/333/333, leftover 1, equal remainders,
tie-break by id → instructor 1 gets 334. Sum = 1000.

Degenerate inputs that must behave: a single instructor; all-zero weights; a total of 1 piastre; a
total of 0.

## Policy dials

Everything tunable lives in `config/revenue.php`, never as a literal in a Service or Action:
`platform_share_bps`, `hold_days`, `minimum_payout_minor`, `zero_engagement_policy`.

Read config at the entry point or in the DTO's named constructor, and pass the values inward. An
Action that reads `config()` itself is harder to test across policy values, and `App\Support` must
never read config at all.

## Zero engagement

If a subscription generated no engagement in a period the weight denominator is zero: `pool = 0`,
`platform = gross`. There is no defensible proportion, so no instructor is credited. This is a
policy dial (`zero_engagement_policy`) precisely because the alternative — an equal split among
enrolled instructors — is equally arguable.
