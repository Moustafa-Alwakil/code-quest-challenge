<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a term cannot be split into an exact accrual schedule (F04).
 *
 * Every case here is checked before a period row exists, because a schedule
 * whose gross does not sum to the price breaks invariant I8 permanently: the
 * missing piastre would sit in deferred revenue for the life of the
 * subscription, and no recognition run would ever release it.
 */
final class AccrualScheduleException extends DomainException
{
    public static function invalidIntervalMonths(int $intervalMonths, int $maximum): self
    {
        return new self("A term runs for 1 to {$maximum} months, got {$intervalMonths}.");
    }

    public static function negativePrice(int $minor, string $currency): self
    {
        return new self("A term cannot be priced below zero, got {$minor} {$currency}.");
    }

    public static function emptyPeriod(int $sequence, string $start, string $end): self
    {
        return new self("Period {$sequence} spans no days: {$start} to {$end}.");
    }

    public static function grossDoesNotSumToPrice(int $allocatedMinor, int $priceMinor, string $currency): self
    {
        return new self("The schedule allocates {$allocatedMinor} {$currency} but the term costs {$priceMinor} {$currency}; the difference would be stranded in deferred revenue.");
    }
}
