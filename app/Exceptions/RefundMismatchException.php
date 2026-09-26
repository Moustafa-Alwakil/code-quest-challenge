<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Two independent computations of a refund disagreed (F09).
 *
 * The refund is worked out from the periods — what was cancelled, plus the
 * unused part of the one that was truncated — and then checked against the
 * `deferred_revenue` balance the ledger has been carrying all along. They are
 * derived from completely different things, so if they agree the number is
 * almost certainly right, and if they do not, something upstream is wrong and
 * the refund must not be written.
 *
 * Throwing takes the whole transaction back out, which is the point: a refund
 * that cannot be reconciled is a refund that should not happen.
 */
final class RefundMismatchException extends RuntimeException
{
    public static function unearned(int $subscriptionId, int $fromPeriods, int $fromLedger, string $currency): self
    {
        return new self(
            "Refund for subscription {$subscriptionId} does not reconcile: the periods say {$fromPeriods} "
            ."{$currency} minor units are unearned, the ledger says {$fromLedger}."
        );
    }

    public static function total(int $subscriptionId, int $refunded, int $price, string $currency): self
    {
        return new self(
            "Full refund for subscription {$subscriptionId} returns {$refunded} {$currency} minor units, "
            ."but the student paid {$price}."
        );
    }
}
