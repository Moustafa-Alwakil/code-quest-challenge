<?php

declare(strict_types=1);

namespace App\Support\Refunds;

use App\Enums\RefundType;

/**
 * What a refund did, or would do (F09).
 *
 * The same object comes back from a `--dry-run` and from the real thing, with
 * `applied` telling them apart. That is what makes the preview trustworthy:
 * it is not a second calculation that agrees with the first, it is the first.
 */
final readonly class RefundOutcome
{
    /**
     * @param array<int, int> $clawedBackByInstructor instructor id => minor units taken back
     */
    private function __construct(
        public RefundType $type,
        public int $refundMinor,
        public int $unearnedMinor,
        public int $clawedBackMinor,
        public int $platformClawedBackMinor,
        public int $periodsCancelled,
        public bool $truncatedAPeriod,
        public array $clawedBackByInstructor,
        public bool $applied,
        public bool $replayed,
    ) {}

    /**
     * @param array<int, int> $clawedBackByInstructor
     */
    public static function of(
        RefundType $type,
        int $refundMinor,
        int $unearnedMinor,
        int $clawedBackMinor,
        int $platformClawedBackMinor,
        int $periodsCancelled,
        bool $truncatedAPeriod,
        array $clawedBackByInstructor,
        bool $applied,
    ): self {
        return new self(
            $type,
            $refundMinor,
            $unearnedMinor,
            $clawedBackMinor,
            $platformClawedBackMinor,
            $periodsCancelled,
            $truncatedAPeriod,
            $clawedBackByInstructor,
            $applied,
            replayed: false,
        );
    }

    /**
     * This term was already refunded, so nothing was written. Not an error: the
     * caller replaying a webhook has done nothing wrong.
     */
    public static function replayOf(RefundType $type): self
    {
        return new self($type, 0, 0, 0, 0, 0, false, [], applied: false, replayed: true);
    }
}
