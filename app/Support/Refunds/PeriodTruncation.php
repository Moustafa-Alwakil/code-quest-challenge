<?php

declare(strict_types=1);

namespace App\Support\Refunds;

use App\Support\Allocator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Splitting the period a refund lands inside (F09).
 *
 * The student used part of it and is owed the rest, and the split has to be
 * exact: `used + unused === gross`, with no piastre invented or lost. That is
 * `Allocator::largestRemainder()` over the two day counts, the same mechanism
 * that split the price across periods in the first place (D-5).
 *
 * **Ties go to the student.** A period of an odd number of days split down the
 * middle leaves one piastre with no principled owner, and the platform absorbs
 * it rather than the person asking for their money back.
 */
final readonly class PeriodTruncation
{
    /** Weight keys, ordered so the allocator's ascending-key tie-break favours the refund. */
    private const UNUSED = 0;

    private const USED = 1;

    private function __construct(
        public int $periodId,
        public CarbonImmutable $newPeriodEnd,
        public int $usedDays,
        public int $usedMinor,
        public int $unusedMinor,
    ) {}

    /**
     * @throws InvalidArgumentException when the effective date is not strictly inside the period
     */
    public static function of(PeriodLine $period, CarbonImmutable $effective): self
    {
        $usedDays = (int) $period->periodStart->diffInDays($effective);
        $unusedDays = $period->days - $usedDays;

        if ($usedDays <= 0 || $unusedDays <= 0) {
            throw new InvalidArgumentException(
                "A truncation needs the effective date strictly inside period {$period->id}; "
                ."got {$usedDays} used of {$period->days} days."
            );
        }

        /**
         * Keys, not day counts, decide the tie — and `UNUSED` is the lower key,
         * so the leftover piastre is refunded rather than kept.
         */
        $split = Allocator::largestRemainder($period->grossMinor, [
            self::UNUSED => $unusedDays,
            self::USED => $usedDays,
        ]);

        return new self(
            $period->id,
            $effective,
            $usedDays,
            $split[self::USED],
            $split[self::UNUSED],
        );
    }
}
