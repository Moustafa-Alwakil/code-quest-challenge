---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Livewire components are presentation only
A component action does six things and stops: receive the interaction, validate, build the DTO, invoke the Action, handle the result, update UI state (dispatch / notify / redirect). Business rules, money maths, Eloquent writes and `DB` calls do not belong here — extract them into an Action.

Authorize inside the action method with a policy or gate. Hiding a button is not authorization; the operation must still be refused when the component method is invoked directly.

Idempotency keys live in `#[Locked]` properties so the browser cannot tamper with them. A disabled button or an Alpine spinner is comfort — the `UNIQUE` index is the guarantee.

Reads: `#[Computed]` properties, pagination and eager loading. Never a query inside a Blade loop; always `wire:key` on loop roots.
