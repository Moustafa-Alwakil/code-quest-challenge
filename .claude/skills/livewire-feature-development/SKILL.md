---
name: livewire-feature-development
description: "How this Instructor Revenue Ledger project structures Livewire 3 components — the student flow (F11) and any interactive UI. Use when creating or modifying a component in app/Livewire, when deciding what belongs in a component versus an Action, when handling validation, authorization, idempotency keys, loading and error state, or a payment whose outcome is still unknown, and when reviewing a component for business logic that has leaked in. Layers on top of the Boost livewire-development skill, which covers Livewire 3 syntax, directives and lifecycle. Do not use for Filament resources or for non-Livewire Laravel code."
---

# Livewire Feature Development

Boost's **`livewire-development`** skill covers Livewire 3 itself: `wire:model.live` vs deferred,
the `App\Livewire` namespace, `$this->dispatch()`, lifecycle hooks, `wire:key`, bundled Alpine,
and the v2→v3 differences. Use it for syntax. Boost's **`tailwindcss-development`** covers styling.

This skill covers what those cannot know: **how this project structures a component.**

## The component contract

A Livewire component is the presentation layer of `revenue-ledger-architecture`'s chain. An action
method does six things and stops:

```php
public function pay(StartCheckoutAction $action): void
{
    $this->authorize('subscribe', Subscription::class);              // 1. authorize

    $validated = $this->validate([                                    // 2. validate
        'planId' => ['required', 'integer', 'exists:plans,id'],
    ]);

    $result = $action(StartCheckoutData::fromLivewire(                // 3. DTO  4. invoke
        userId: auth()->id(),
        planId: (int) $validated['planId'],
        intentKey: $this->intentKey,
    ));

    match ($result->status) {                                         // 5. handle result
        CheckoutStatus::Succeeded => $this->redirect(route('subscription'), navigate: true),
        CheckoutStatus::Pending   => $this->confirming = true,        // 6. UI state
        CheckoutStatus::Failed    => $this->addError('payment', $result->message),
    };
}
```

**Belongs in the component:** loading and disabled state, notifications, redirects, dispatched
events, error wording, polling, what the view needs.

**Never in the component:** money arithmetic, allocation, ledger writes, `DB` calls, Eloquent
writes, provider calls, transaction management, retry logic, business rules that would still be
true if the UI did not exist.

**The test for a leak:** if a rule would still hold when the operation is invoked from an Artisan
command, it belongs in an Action.

## Method size

If a component method is longer than the one above, something moved in that should not have.
Extract to an Action — not to a private method on the component. A private helper full of business
logic is the same defect with a smaller blast radius.

## Validation

Validate at the component boundary and nowhere else in the chain. Livewire validation is the input
guard: shape, presence, types, existence.

Business rules are not validation. "This student already has a live subscription" is enforced by a
`UNIQUE` constraint and checked inside the Action — a validation rule for it would be a race
condition with a friendly error message.

Convert validated data into a DTO immediately. Raw component properties never cross into an Action.

Real-time feedback: `wire:model.live.debounce.500ms` plus `$this->validateOnly()` in an `updated`
hook — never on every keystroke against the database.

## Authorization

Enforce it in the action method, with a policy or gate. Hiding a button is presentation, not
security: a component method is a public HTTP endpoint and must refuse the operation when invoked
directly.

`#[Locked]` on any property the server must trust — ids, idempotency keys, amounts. Without it the
browser can change the value between requests.

Guard routes with middleware as well, so an unauthenticated request never reaches `mount()`.

## Idempotency at the UI edge

`docs/features/11-student-flow.md`. A double-clicked **Pay** button is the same bug as a double-run
payout command, and it gets the same answer: **a unique index, not a disabled button.**

- Generate the intent key once in `mount()`, hold it in a `#[Locked]` property.
- Send the same key on every retry of that intent; `UNIQUE payments.idempotency_key` collapses
  duplicates to one payment.
- Alpine's disabled button and spinner are comfort on top. Never the guarantee.

## The `unknown` state in the UI

A charge that times out is neither a success nor a failure (`D‑8`, applied inbound). The component
shows "Confirming your payment…" and polls:

```blade
<div @if ($confirming) wire:poll.5s="refreshStatus" @endif>
```

Never tell the student the payment failed because the request timed out, and never re-charge from
the UI. `payments:reconcile` resolves it; the component only reflects what the ledger says.

## Reads and performance

- `#[Computed]` properties for derived data — cached per request, so repeated use in a view is free.
- Paginate lists; eager-load the relationships the view touches. The catalog shows a course's
  instructor, so load it.
- Never a query inside a Blade loop. Never `->get()` on an unbounded set.
- `wire:key` on every loop root, keyed by model id.
- Keep component state small: every public property is serialized to the browser and back on every
  request. Derive with `#[Computed]` instead of storing.
- `#[Lazy]` for a component whose initial payload is expensive.

## Events

Use them to coordinate UI between components — a toast, a refreshed sibling list. Do not use them
to move money: a business workflow hidden behind a chain of listeners is untestable and invisible
to the reviewer. The Action is the workflow; the event announces that it happened.

## Scope discipline

`docs/features/11-student-flow.md` is a **closed list of five screens**, and `PLAN.md` D‑11 sets the
line: *if it does not create, end or refund a subscription, it is not built.* No video playback,
progress tracking, quizzes, certificates, search, reviews, profile editing or instructor pages.

F11 is Day 6 and discretionary — it carries no grade weight, and the escape hatch is to ship Breeze
plus one Subscribe page. Build it small and correct.

## Testing

Test observable behaviour, not internals: what renders, what a user interaction does, what the
component refuses. `ledger-testing` covers the details.

```php
Livewire::actingAs($student)
    ->test(Checkout::class)
    ->set('planId', $plan->id)
    ->call('pay')
    ->assertRedirect(route('subscription'));
```

Assert the outcome in the database — one subscription, one payment, one provider charge — not that
a particular private method ran.
