<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one slice of a term's revenue stands (F04, F05).
 *
 * There is no `recognizing` state (R3): F05's compare-and-set and all of its
 * writes share one transaction, so a crash rolls the status back with them and
 * the next run picks the period up. `cancelled` is F09's truncation of a term
 * that was refunded before the period was earned.
 */
enum AccrualPeriodStatus: string
{
    case SCHEDULED = 'scheduled';
    case RECOGNIZED = 'recognized';
    case CANCELLED = 'cancelled';
}
