<?php

declare(strict_types=1);

namespace App\Support\Accrual;

/**
 * What one attempt to recognize a period did (F05).
 *
 * `skipped` is a success, not a failure: the compare-and-set found the period
 * already recognized by another server, or cancelled by a refund, and the
 * correct response to both is to write nothing and carry on. A run that treated
 * it as an error would fail every time two workers swept at once.
 */
final readonly class RecognitionOutcome
{
    private function __construct(
        public int $periodId,
        public bool $recognized,
        public int $poolMinor,
        public int $platformMinor,
        public int $allocationCount,
    ) {}

    public static function recognized(int $periodId, int $poolMinor, int $platformMinor, int $allocationCount): self
    {
        return new self($periodId, true, $poolMinor, $platformMinor, $allocationCount);
    }

    /**
     * The period was not this run's to recognize.
     */
    public static function skipped(int $periodId): self
    {
        return new self($periodId, false, 0, 0, 0);
    }
}
