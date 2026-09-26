<?php

declare(strict_types=1);

namespace App\Support\Payouts;

/**
 * What one attempt to reserve an instructor's balance did (F06).
 *
 * `skipped` covers three different reasons and names each of them, because the
 * run's report is the only place an operator finds out why an instructor was
 * not paid — and "below the minimum" and "already has an item in this run" are
 * very different answers to that question.
 */
final readonly class ReservationOutcome
{
    public const REASON_BELOW_MINIMUM = 'below the minimum';

    public const REASON_ALREADY_RESERVED = 'already reserved in this run';

    public const REASON_NO_SNAPSHOT = 'no balance snapshot';

    private function __construct(
        public int $instructorId,
        public bool $reserved,
        public int $amountMinor,
        public ?int $payoutItemId,
        public ?string $skippedReason,
    ) {}

    public static function reserved(int $instructorId, int $amountMinor, int $payoutItemId): self
    {
        return new self($instructorId, true, $amountMinor, $payoutItemId, null);
    }

    public static function skipped(int $instructorId, string $reason): self
    {
        return new self($instructorId, false, 0, null, $reason);
    }
}
