---
paths:
  - 'app/Support/**'
---

# Support

## Prefer Illuminate\Support\Str and Number over native functions
`Money`, `Allocator` and `RevenueSplit` are ordinary Laravel code. Reach for `Str::trim()`, `Str::upper()`, `Str::padRight()`, `Str::isMatch()` and `Number::format()` before the native `trim`, `strtoupper`, `str_pad`, `preg_match` or `number_format`.

Drop to a native function only where `Str`/`Number` has no equivalent, and say why in a comment. In `Money` that is `preg_match` for the decimal parse (named capture groups, which `Str::match()` does not expose), plus `sprintf`, `intdiv` and `abs`.

This applies everywhere in `app/`, not only here.

## Support does no I/O, so it stays property-testable
No database, no config lookups, no clock, no `App\Models`. Inputs in, values out, deterministic on every call — this is what lets `largestRemainder` be tested against a thousand random cases.

Policy values (`instructor_share_bps`, `hold_days`) are read from `config/revenue.php` by the caller and passed in. Asserted by `arch('support does not reach for state')`.

## Money is integer minor units
No float, no `round()`, no `DECIMAL` arithmetic. Asserted by `arch('money never goes near a float')`.
