<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a subscription's term stands (F04).
 *
 * Deliberately cosmetic: money is driven by `accrual_periods`, never by this
 * column, so an expiry sweep that has not run yet cannot cost anyone a piastre.
 * `expired` means the term is over, `refunded` that F09 cancelled it.
 */
enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case REFUNDED = 'refunded';
    case EXPIRED = 'expired';
}
