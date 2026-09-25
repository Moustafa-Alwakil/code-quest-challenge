# F09 — Refunds & Clawbacks

> **Day:** 5 · **Depends on:** F04, F05 (F06–F08 for the already-paid case)
> **Implements:** D‑7 · **Plan refs:** §11 · **Video scenario 6**
> **Grade areas:** Correctness (25%), System design (20%)

## Goal

Let a student leave mid-term with a fair refund, guarantee instructors are never paid for time
that wasn't delivered, and handle the rare case where money already paid out must be recovered.

## The key refinement

With accrual (D‑1), **a pro-rata refund of unused time corresponds exactly to the periods not yet
earned.** So the normal refund path never touches instructor earnings at all. Clawbacks only
arise for refunds that exceed unused time — a full refund, a chargeback, a goodwill gesture.

*(This sharpens PLAN §11: its "zone 1" is the normal path; zones 2 and 3 are reachable only
through a full refund.)* It is also the strongest single sentence for the video: *"In my design a
student cancelling doesn't cost any instructor anything — because nobody was ever credited for
the months the student didn't use."*

## Refunds are recorded facts

Like payments (F04), a refund is executed at the payment gateway, outside this system. F09
records it — keyed by the gateway's refund reference — and applies its consequences to the
ledger. *(R25.)*

## Refund types

| Type | Amount | Instructor impact |
|---|---|---|
| `prorata` (default) | price of unused time from the effective date | **none** |
| `full` | the whole payment | clawback of every recognized allocation |

Arbitrary partial amounts are out of scope.

**Entry point:** `refunds:issue {subscription} --external-ref= {--full} {--effective=today}
{--dry-run} {--reason=}`. `--dry-run` computes and prints the refund and the per-instructor
impact without writing — through the same code path, so the preview is the number applied.

## Data model

### `refunds`

`payment_id`, `subscription_id`, `type`, `amount_minor`, `effective_at` (DATE), `external_ref`,
`reason`, `created_at`.

- **UNIQUE** `subscription_id` — one refund per subscription; a second is a no-op
- **UNIQUE** `external_ref`, NOT NULL — the gateway's refund id

## Components

### `ApplyProrataRefund` — one DB transaction

1. Lock the subscription `FOR UPDATE`; CAS `active → refunded`.
2. Find the current period *P* (`period_start ≤ E < period_end`, status `scheduled`), where *E*
   is the effective date.
3. **Truncate P.**
   - `E = P.period_start` → cancel *P* entirely.
   - Otherwise split `P.gross` into `[used, unused]` by days with the Allocator (ties go to the
     student). Set `P.period_end = E`, `days = used days`, `gross = used`. Recognize *P*
     immediately via F05's action — the used days are earned from that period's engagement.
4. Cancel every later `scheduled` period.
5. `refund = Σ cancelled gross + unused part of P`. **Assert it equals the remaining
   `deferred_revenue[sub]` balance on the ledger** — two independent computations must agree.
6. Insert the `refunds` row; post `refund_unearned`: DR `deferred_revenue[sub]` +refund,
   CR `platform_cash[0]` −refund. Deferred revenue for the subscription is now exactly 0.
7. Commit.

### `ApplyFullRefund` — one DB transaction

1. Lock the subscription; CAS → `refunded`.
2. Cancel all `scheduled` periods; post `refund_unearned` for the remaining deferred balance.
3. For every allocation of every recognized period — **exact reversal, no re-rounding:**
   - not released → set `clawed_back_at`; snapshot `held −a`, `clawed_back +a`
   - released → snapshot `available −a` (may go negative, D‑7), `clawed_back +a`
4. Post one `refund_clawback` transaction (reference: the refund), legs **aggregated per
   account** so no account appears twice: DR `instructor_payable[i]` for each instructor,
   DR `platform_revenue[0]` for the platform's recognized share, CR `platform_cash[0]` for the total.
5. Assert total refunded = price. Commit.

## Rules

- **Exact reversal.** Clawback amounts equal the original allocation amounts. Recomputing shares
  would re-round and could create or destroy piastres.
- Clawing back **held** money is free — the hold (D‑6) exists to make that the common case.
- Negative `available` carries forward; F06 skips negative and sub-minimum balances.
- A clawback never touches a reserved or in-flight payout item. That item pays what it reserved;
  the negative nets next time.
- Lock order: subscription first, then instructor balances ascending — same order everywhere.
- The gateway executing the refund is assumed to succeed; this system records the outcome, not the
  attempt. Documented assumption.

## Edge cases

| Case | Expected |
|---|---|
| refund on day one (`E = term_start`) | first period cancelled; full price back; no clawback — nothing was earned |
| refund exactly on a period boundary | no truncation; that and later periods cancelled |
| refund after the term is fully recognized | pro-rata amount 0; status changes, no money moves |
| refund twice (same or different `external_ref`) | unique `subscription_id` → no-op |
| refund concurrent with `ledger:accrue` on *P* | subscription lock + period CAS; exactly one of them recognizes *P* |
| refund concurrent with `payouts:run` for an affected instructor | row locks in the fixed order; reserved amounts untouched |
| instructor goes negative and stops teaching | unrecoverable balance — documented limitation (D‑7) |

## Acceptance criteria / tests

- [ ] **Pro-rata mid-annual:** later periods cancelled; instructor snapshots change only by the
      truncated period's recognition; `deferred_revenue[sub] = 0`;
      `refund + Σ recognized gross = price`
- [ ] `E = term_start` → full price back, zero clawback
- [ ] `--dry-run` preview equals the amount then applied; dry run writes nothing
- [ ] Full refund while allocations are held → `held` drops, `available` unchanged
- [ ] Full refund after payout → `available` negative; later earnings net it; payouts skip the
      instructor until the balance is back above the minimum
- [ ] Refund twice → one refund
- [ ] Concurrency with accrue
- [ ] `ledger:verify` green after every test

## Demo hook

Scenario 6: `refunds:issue` on DemoSeeder's S3 — an annual subscription that started ~5 months
ago — first with `--dry-run`, then for real → periods 6–12 cancelled, instructor balances
untouched. Then the `--full` variant on another subscription, showing a negative balance carried
forward in Filament.
