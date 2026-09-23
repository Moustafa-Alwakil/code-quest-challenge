---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Commands are entry points, held to the component contract
A command parses its options into a DTO and invokes an Action. No business logic, no Eloquent, no ledger maths in `handle()`.

`Cache::lock` at the top of a run is an optimization: when it cannot be acquired, print a line and `return self::SUCCESS`. Never rely on it for correctness — the `UNIQUE` index does that.

Iterate with keyset pagination and dispatch chunk jobs in a `Bus::batch()`. Provide `--sync` so tests and demos can run inline, and `--dry-run` where a command moves money.
