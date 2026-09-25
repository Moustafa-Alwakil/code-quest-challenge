<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a ledger entry exists (F03).
 *
 * Part of the unique key `(entry_type, reference_type, reference_id,
 * account_type, account_id)`, which is what makes writing an entry idempotent:
 * the same business fact can only ever be recorded once.
 *
 * A ledger row is never updated or deleted. A correction is a new entry with
 * its own entry type — `payout_reversed` undoes `payout_reserved`,
 * `refund_clawback` undoes part of `period_recognized`.
 */
enum LedgerEntryType: string
{
    case PAYMENT_RECEIVED = 'payment_received';
    case PERIOD_RECOGNIZED = 'period_recognized';
    case PAYOUT_RESERVED = 'payout_reserved';
    case PAYOUT_SETTLED = 'payout_settled';
    case PAYOUT_REVERSED = 'payout_reversed';
    case REFUND_UNEARNED = 'refund_unearned';
    case REFUND_CLAWBACK = 'refund_clawback';
}
