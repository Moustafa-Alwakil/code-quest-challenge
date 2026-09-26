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
}
