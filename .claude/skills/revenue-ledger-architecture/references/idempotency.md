# Idempotency — the ladder

`docs/PLAN.md` §9 and §10. The claim this design has to back up: **if Redis disappeared entirely,
no instructor would be paid twice.**

## Correctness (rows 1–9) — enforced by the database

| # | Risk | Mechanism |
| --- | --- | --- |
| 1 | Duplicate payment ingestion | `UNIQUE payments.external_ref`, `UNIQUE payments.idempotency_key` |
| 2 | Period recognized twice | `UNIQUE (subscription_id, period_start)` + status CAS |
| 3 | Instructor credited twice for a period | `UNIQUE (accrual_period_id, instructor_id)` |
| 4 | Duplicate ledger entry | `UNIQUE (entry_type, reference_type, reference_id, account_type, account_id)` |
| 5 | Two concurrent payout runs | `UNIQUE payout_runs.run_key` |
| 6 | Two payouts to one instructor in a run | `UNIQUE (payout_run_id, instructor_id)` |
| 7 | Re-run pays already-paid balance | reserve-before-send: balance leaves `available` atomically |
| 8 | Retried job re-transfers | same `idempotency_key` → provider dedups |
| 9 | Invalid state transition | `UPDATE … WHERE status = ?`, assert `affected === 1` |

## Efficiency (row 10) — Redis

`Cache::lock`, `ShouldBeUnique`, `WithoutOverlapping`, `onOneServer`. These stop wasted work. They
never appear in a sentence explaining why the money is right.

A lock can expire, a Redis node can fail over, a clock can skew, the OS can pause a process past
its TTL. A unique index in the same database as the data cannot be wrong.

## Writing it

**Idempotent insert.** The unique index makes writing a ledger entry itself idempotent, so nothing
downstream has to be careful. Replay a retried job, a re-run command or a duplicated webhook and it
is swallowed:

```php
$inserted = LedgerEntry::query()->insertOrIgnore($rows);
```

**Compare-and-swap transition.** Every transition, no exceptions:

```php
$affected = PayoutItem::query()
    ->where('id', $itemId)
    ->where('status', PayoutItemStatus::Submitted)
    ->update(['status' => PayoutItemStatus::Succeeded, 'settled_at' => now()]);

if ($affected !== 1) {
    return;   // someone else already moved it; not an error
}
```

`affected === 0` is a normal outcome, not an exception. It means a concurrent worker got there
first, which is exactly what the design intends.

## The four states of an unreliable provider

`docs/PLAN.md` D‑8. The provider gives three outcomes; the system models four. This is the single
most important decision in the submission.

| Provider outcome | Item status | Money | Next action |
| --- | --- | --- | --- |
| Success | `succeeded` | settled | none |
| Permanent failure | `failed` | returned to available | next run |
| Timeout (may have succeeded) | `unknown` | **stays reserved** | reconciliation sweep |
| No response / worker died | `submitted` | **stays reserved** | reconciliation sweep |

A timeout is never `failed`. Treating it as a failure is the classic double-payment bug: the money
moved, the app thinks it did not, the next run pays again. Treating it as success is the classic
silent-loss bug. The only honest answer is "we do not know yet", modelled explicitly, with the money
frozen until the provider tells us the truth via `getStatus()`.

The same discipline runs inbound: a timed-out charge leaves the subscription inactive and the
payment `unknown`, and is never re-sent blindly.

## The checkout parallel

A double-clicked **Pay** button is the same bug as a double-run payout command, and it gets the same
answer: **a unique index, not a disabled button.** The disabled button and the Alpine spinner are
the UI-layer comfort on top.
