---
paths:
  - 'app/DTOs/**'
---

# DTOs

## DTOs: final readonly input contracts, scalars only
Every Action takes a DTO, never an array. `final readonly class RunPayoutsData` with promoted, typed constructor properties.

Build them with named constructors at the boundary — `fromLivewire()`, `fromCommand()`, `fromValidated()` — so Livewire, console and request shapes never leak inward.

Carry scalars, enums, `Carbon` and `App\Support\Money` only. No Eloquent models, no `Request`, no Livewire component, no `Illuminate` imports. Money fields are `int` minor units.

## A DTO may read the clock, once, in a named constructor (R27)
Only to default an absent input or to reject one that could not have happened yet, and the resolved instant is then carried as a field. Never in a plain constructor; never twice in one construction, such that two fields could disagree; and never on a job-side rebuild — a job carries the already-resolved value as a scalar (R15), so a retry acts on the window its dispatch intended rather than on a window that has since moved.

`ExpireSubscriptionsData` reading "today" is harmless because the sweep moves a cosmetic status. F05's `--date=today` decides which periods are recognized, so the same shape there is a money decision: resolve once at the boundary and carry it.

Spell it `CarbonImmutable::now()`, not `now()` — the helper returns a mutable `Illuminate\Support\Carbon`. Tests control both with `travelTo()`.
