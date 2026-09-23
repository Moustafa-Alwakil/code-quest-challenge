# F03 — Ledger Core

> **Day:** 2 · **Depends on:** F01 · **Implements:** D‑9, ledger half of D‑10
> **Plan refs:** §4 (D‑9), §5.4, §5.6, §9 row 4
> **Grade areas:** Correctness (25%), Data integrity (15%)

## Goal

An append-only, double-entry ledger that is the single source of truth for every piastre; a
per-instructor balance snapshot for O(1) reads; and a verifier that proves the two agree.

## Data model

### `ledger_entries` (append-only)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `transaction_uuid` | CHAR(36) | groups legs; indexed |
| `account_type` | ENUM | `platform_cash`, `deferred_revenue`, `platform_revenue`, `instructor_payable`, `provider_in_transit` |
| `account_id` | BIGINT UNSIGNED **NOT NULL** | `0` for singleton accounts — see the NULL gotcha below |
| `amount_minor` | BIGINT **signed** | debit positive, credit negative |
| `currency` | CHAR(3) | |
| `entry_type` | VARCHAR | `payment_received`, `period_recognized`, `payout_reserved`, `payout_settled`, `payout_reversed`, `refund_unearned`, `refund_clawback` |
| `reference_type`, `reference_id` | VARCHAR, BIGINT NOT NULL | the business row this entry records |
| `created_at` | TIMESTAMP | no `updated_at` |

- **UNIQUE** `(entry_type, reference_type, reference_id, account_type, account_id)` — makes
  writing an entry idempotent.
- **INDEX** `(account_type, account_id, id)` — per-account history and balance recomputation.

### Account keying

| Account | `account_id` | Why |
|---|---|---|
| `platform_cash` | 0 | singleton |
| `platform_revenue` | 0 | singleton |
| `deferred_revenue` | subscription id | lets us assert each subscription's liability returns to exactly 0 |
| `instructor_payable` | instructor id | what we owe each instructor |
| `provider_in_transit` | instructor id | money reserved for / sent to each instructor, not yet confirmed |

### `instructor_balances` (snapshot — a cache with a provable source of truth)

`instructor_id` PK · `currency` · `earned_minor` · `clawed_back_minor` · `held_minor` ·
`available_minor` (**signed** — may go negative, D‑7) · `reserved_minor` · `paid_minor` ·
`last_ledger_entry_id` · `updated_at`.

## Balance definitions — the contract `ledger:verify` checks

Liabilities are credit-normal, so "owed" values are the negated ledger sum. The snapshot stores
them as positive numbers.

| Field | Recomputed from |
|---|---|
| `earned` | credits to `instructor_payable[i]` with `entry_type = period_recognized` |
| `clawed_back` | debits to `instructor_payable[i]` with `entry_type = refund_clawback` |
| `reserved` | owed balance of `provider_in_transit[i]` |
| `paid` | Σ `payout_settled` amounts for instructor *i* |
| `held` | Σ `earning_allocations.amount` where `released_at` and `clawed_back_at` are NULL (F05) |
| `available` | owed balance of `instructor_payable[i]` − `held` |
| **`outstanding`** (derived) | `earned − clawed_back − paid` |

**Identity:** `outstanding = available + held + reserved`. Checked for every instructor. This is
the one line that answers the brief's *owed / paid / outstanding* question and proves it adds up.

## Components

| Component | Responsibility |
|---|---|
| `LedgerEntry` model | No `updated_at`. `updating` and `deleting` model events throw `ImmutableLedgerException`. |
| `LedgerTransaction` (value object) | `entry_type`, reference, legs. Validates: ≥ 2 legs, Σ = 0, one currency, **no two legs on the same account**. |
| `LedgerPoster::post(transaction, snapshotDeltas)` | Writes legs and applies snapshot deltas — only if the legs were actually inserted. |
| `ledger:verify` | Recomputes everything and reports mismatches. |
| `ledger:rebuild-balances` *(stretch)* | Rebuilds the snapshot from the ledger — the repair path. |

### `LedgerPoster::post` behaviour

1. Assert it is running **inside** an open DB transaction — callers combine posting with a
   status change (CAS) and both must commit or roll back together.
2. `insertOrIgnore` all legs in one statement.
3. Affected rows = N → apply snapshot deltas with atomic `UPDATE … SET x = x + ?` (upsert the
   snapshot row if missing), in **ascending instructor id order**. Return `true`.
4. Affected rows = 0 → a replay. Apply nothing. Return `false`.
5. Anything in between → `LedgerIntegrityException` (the caller's transaction rolls back).

Snapshot deltas are supplied explicitly by the calling action (e.g. a clawback splits between
`held` and `available` depending on allocation state). `ledger:verify` catches any action that
supplies the wrong deltas.

### `ledger:verify`

Chunked, keyset-paginated. Options `--instructor=`, `--fail-fast`. Exit code 1 on any failure,
so it can gate CI and alert from the scheduler.

| # | Check |
|---|---|
| 1 | Σ of all ledger amounts = 0 |
| 2 | every `transaction_uuid` sums to 0 |
| 3 | every snapshot field = its recomputed value |
| 4 | `outstanding = available + held + reserved` per instructor |
| 5 | `deferred_revenue[sub]` = 0 for every subscription whose periods are all recognized or cancelled; never negative |

At production scale, checks 1–2 run incrementally from a watermark rather than over the full table.

## Rules

- Never UPDATE or DELETE a ledger row. Corrections are new entries with their own `entry_type`.
- Every posting happens inside the same DB transaction as the business state change it records.
- **The NULL gotcha.** MySQL unique indexes treat NULLs as distinct. A nullable column inside
  an idempotency key silently disables the key. `account_id` and `reference_id` are NOT NULL;
  singleton accounts use `0`.
- Snapshot updates are atomic SQL increments — never read-modify-write in PHP.
- Multi-instructor updates always touch rows in ascending instructor id to avoid deadlocks.

## Edge cases

| Case | Expected |
|---|---|
| same transaction posted twice | second is a no-op, snapshot unchanged |
| partial duplicate (some legs exist) | `LedgerIntegrityException`, full rollback |
| first posting for a new instructor | snapshot row upserted |
| concurrent postings to one instructor | serialized by the row lock taken by the UPDATE |
| available goes negative | allowed (signed column) |
| legs in two currencies | rejected by `LedgerTransaction` |

## Acceptance criteria

- [ ] Posting twice produces one set of rows and one snapshot change
- [ ] Model update/delete throw
- [ ] `ledger:verify` passes on clean data and **fails with exit 1** when a snapshot row is
      deliberately corrupted
- [ ] Identity holds for every instructor after DemoSeeder

## Tests

- Unit: `LedgerTransaction` validation (unbalanced, mixed currency, duplicate account, one leg)
- Integration: replay no-op; partial duplicate rollback; immutability; verify green; verify red
  on a tampered snapshot; outstanding identity

## Demo hook

Corrupt one `instructor_balances` row in tinker, run `ledger:verify` → red, with the exact
mismatch. *"The snapshot is a cache. This is how I know it's telling the truth."*

## Notes

Production hardening, documented not built: monthly partitioning of `ledger_entries`; revoking
UPDATE/DELETE grants on the table from the application's DB user so immutability is enforced by
MySQL, not just by the model.
