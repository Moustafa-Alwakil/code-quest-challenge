<?php

declare(strict_types=1);

namespace App\DTOs\Payouts;

use InvalidArgumentException;

/**
 * One instructor's reservation, inside one run (F06).
 *
 * The run's policy travels with each reservation rather than being re-read per
 * instructor: a run that started under one minimum must reserve every
 * instructor under that same minimum, even if the config changed underneath it
 * halfway through a sweep of half a million balances.
 */
final readonly class ReserveInstructorBalanceData
{
    private function __construct(
        public int $payoutRunId,
        public int $instructorId,
        public int $minimumAmountMinor,
        public string $currency,
    ) {}

    /**
     * @throws InvalidArgumentException on a non-positive id or a negative minimum
     */
    public static function forInstructor(
        int $payoutRunId,
        int $instructorId,
        int $minimumAmountMinor,
        string $currency,
    ): self {
        if ($payoutRunId <= 0) {
            throw new InvalidArgumentException("A reservation needs a positive run id, got {$payoutRunId}.");
        }

        if ($instructorId <= 0) {
            throw new InvalidArgumentException("A reservation needs a positive instructor id, got {$instructorId}.");
        }

        if ($minimumAmountMinor < 0) {
            throw new InvalidArgumentException("A payout minimum cannot be negative, got {$minimumAmountMinor}.");
        }

        return new self($payoutRunId, $instructorId, $minimumAmountMinor, $currency);
    }
}
