<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which call one audit row records (F07).
 *
 * The distinction is what makes `payout_attempts` worth reading: three rows
 * against one item saying `transfer`, `status`, `status` is a retry that asked
 * before it acted — which is the behaviour that keeps a timed-out transfer from
 * being sent twice.
 */
enum PayoutAttemptOperation: string
{
    case TRANSFER = 'transfer';

    case STATUS = 'status';

    /**
     * A person established the outcome and told the system (F08).
     *
     * The only operation here that is not a provider call, and the only one
     * that moves money on a human's word — which is exactly why it is recorded
     * beside the provider's own answers rather than in a log nobody reads.
     */
    case MANUAL = 'manual';
}
