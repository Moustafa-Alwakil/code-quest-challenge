---
paths:
  - 'app/DTOs/**'
---

# DTOs

## DTOs: final readonly input contracts, scalars only
Every Action takes a DTO, never an array. `final readonly class RunPayoutsData` with promoted, typed constructor properties.

Build them with named constructors at the boundary — `fromLivewire()`, `fromCommand()`, `fromValidated()` — so Livewire, console and request shapes never leak inward.

Carry scalars, enums, `Carbon` and `App\Support\Money` only. No Eloquent models, no `Request`, no Livewire component, no `Illuminate` imports. Money fields are `int` minor units.
