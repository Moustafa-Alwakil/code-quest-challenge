# F10 — Filament Admin (Read-only)

> **Day:** 6 · **Depends on:** F03, F06 (F08 for the full status set)
> **Plan refs:** §13.1 · **Required item 6**
> **Grade areas:** Laravel implementation (15%) — and it's the lens for every failure demo

## Goal

One read-only admin screen that answers, per instructor, the brief's three questions — **owed,
paid, outstanding** — and shows payout history.

## Access

The Filament panel is already scaffolded (`AdminPanelProvider`). `User::canAccessPanel()` returns
`is_admin`. The admin user is seeded by `DemoSeeder`; credentials go in the README.

## `InstructorResource`

### List

Reads the snapshot only — **never** a ledger `SUM()` at request time.

| Column | Source |
|---|---|
| Name | `instructors` |
| Available | `available_minor` — red when negative, tooltip *"carried forward, netted against future earnings"* |
| Held | `held_minor` |
| In flight | `reserved_minor` |
| Earned (lifetime) | `earned_minor` |
| Clawed back | `clawed_back_minor` |
| Paid (lifetime) | `paid_minor` |
| **Outstanding** | `earned − clawed_back − paid` |
| Last payout | date + status badge (sub-select, not N+1) |

- Filters: negative balance · has items needing review · outstanding > 0
- Sort: outstanding, available
- Money formatted from minor units as `EGP 1,234.56`

### View page

- Header stats with the same numbers, plus the identity spelled out:
  **Outstanding = Available + Held + In flight.** The screen explains its own arithmetic.
- **Relation manager — Payout history:** run key, amount, status badge, provider reference,
  submitted / settled timestamps, attempt count; expandable to `payout_attempts`.

| Status | Badge |
|---|---|
| succeeded | green |
| failed | red |
| unknown | amber |
| needs_review | red, outlined |
| reserved / submitted | grey |

- **Relation manager — Ledger entries:** this instructor's `instructor_payable` and
  `provider_in_transit` entries, id descending, **simple pagination** (no `COUNT(*)` over a
  tens-of-millions table), served by INDEX `(account_type, account_id, id)`.

### Read-only

`canCreate`, `canEdit`, `canDelete` → false. No bulk actions. No create/edit routes.

## `PayoutRunResource`

Runs with item counts by status; view page lists the run's items. Cheap to add, and it makes
scenarios 1, 2 and 4 legible on camera. First to go if Day 6 runs short (PLAN §16).

## Performance

List page: one query joining `instructors` → `instructor_balances`, plus a sub-select for the
last payout. Verify no N+1 with DemoSeeder data before recording.

## Acceptance criteria / tests (Filament's Livewire testing helpers)

- [ ] List renders the expected balances for a seeded instructor
- [ ] Values shown equal the snapshot row exactly
- [ ] Non-admin user is forbidden
- [ ] No create/edit/delete actions or routes exist

## Demo hook

Side by side with the terminal for every failure scenario: an item sits amber in `unknown`,
reconcile runs, it turns green; a refunded instructor's available balance turns red.
