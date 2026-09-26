<?php

declare(strict_types=1);

namespace App\DTOs\Accrual;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The maturation sweep's window (D-6, F05).
 *
 * Runs at the end of `ledger:accrue` and at the start of `payouts:run`, so the
 * instant is supplied by whichever run is asking rather than read here: a
 * payout run must decide availability against the same clock it reserves
 * against.
 */
final readonly class ReleaseMaturedEarningsData
{
    private const DEFAULT_CHUNK_SIZE = 1000;

    private function __construct(
        public CarbonImmutable $asOf,
        public string $currency,
        public int $chunkSize,
    ) {}

    /**
     * @throws InvalidArgumentException on a chunk below 1
     */
    public static function asOf(CarbonImmutable $asOf, string $currency, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException("The release chunk size must be at least 1, got {$chunkSize}.");
        }

        return new self($asOf, $currency, $chunkSize);
    }
}
