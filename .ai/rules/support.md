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

## A date input is normalized to midnight UTC by the function that consumes it (R26)
`App\Support` has no callers it can vet — seeders, tests, F09 and any future importer all reach it — so a documented precondition is not a kept one. A function whose domain is a calendar date rebuilds its anchor itself:

```php
$anchor = CarbonImmutable::parse($termStart->toDateString(), 'UTC');
```

Never `->utc()` (it moves an *early-morning* Cairo capture to the previous day — and a *late-evening* Honolulu one to the next — splitting `term_start` from the `captured_at` it is defined to equal; for a zone ahead of UTC the date slips on 00:30, not on 23:00, so a 23:00 case is the one that fails to catch this) and never `startOfDay()` in the caller's zone — where a DST shift removes local midnight, `startOfDay()` returns 01:00 and `(int) $start->diffInDays($end)` truncates a 30-day period to 29. That is a wrong largest-remainder weight, not a display bug, and `Σ gross === price` still holds, so no summing assertion catches it. Assert `days` against the calendar distance between the two date strings instead.

Parsing a complete `Y-m-d` string is deterministic and is not a clock read, so this stays inside the no-I/O rule above. Mind where that boundary is: `parse()` consults test-now for any input that leaves a component unspecified — `'14:00'` resolves against today's date, `'+1 day'` against now — so a **complete** `Y-m-d` never reaches it, and a partial string inside `App\Support` is a clock read however it is spelled. Use `Carbon\CarbonImmutable` directly; the `Date` facade is on the banned list in `arch('support does not reach for state')`.
