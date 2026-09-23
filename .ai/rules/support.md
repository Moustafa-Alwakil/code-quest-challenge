---
paths:
  - 'app/Support/**'
---

# Support

## Support is pure PHP with no framework
`Money`, `Allocator` and `RevenueSplit` are pure: no `Illuminate` imports, no facades, no database, no config lookups, no clock. Inputs in, values out, deterministic on every call.

This is asserted by an arch test, and it is what makes property-based testing of `largestRemainder` possible. Policy values (`platform_share_bps`, `hold_days`) are read from `config/revenue.php` by the caller and passed in.
