<?php

declare(strict_types=1);

namespace App\Support\Refunds;

use App\Enums\RefundType;
use Carbon\CarbonImmutable;

/**
 * What a refund will do to a term, decided before anything is written (F09).
 *
 * Pure: periods in, decisions out. The same function produces the `--dry-run`
 * preview and the numbers the real run applies, so the preview cannot drift
 * from the outcome — they are not two code paths that agree, they are one.
 *
 * The plan covers the *unearned* side only. A full refund's clawback of
 * earnings is `ClawbackPlan`, because it depends on allocations rather than on
 * periods and because the pro-rata path never needs it at all.
 */
final readonly class RefundPlan
{
    /**
     * @param list<int> $cancelledPeriodIds
     */
    private function __construct(
        public RefundType $type,
        public CarbonImmutable $effectiveAt,
        public array $cancelledPeriodIds,
        public int $cancelledGrossMinor,
        public ?PeriodTruncation $truncation,
    ) {}

    /**
     * Give back the time the student has not used.
     *
     * Three shapes, and the boundaries decide which:
     *
     * - the effective date sits inside a scheduled period → that period is
     *   truncated to the used days and the rest is refunded;
     * - it falls exactly on a period start → nothing to truncate, that period
     *   and every later one are cancelled whole;
     * - the term is already fully recognized → nothing is scheduled, so the
     *   refund is zero and only the status moves.
     *
     * @param list<PeriodLine> $periods every period of the term, any status
     */
    public static function forProrata(array $periods, CarbonImmutable $effectiveAt): self
    {
        $truncation = null;
        $cancelledIds = [];
        $cancelledGross = 0;

        foreach ($periods as $period) {
            if (! $period->isScheduled()) {
                continue;
            }

            /** Entirely in the future: none of it was delivered. */
            if ($period->periodStart->greaterThanOrEqualTo($effectiveAt)) {
                $cancelledIds[] = $period->id;
                $cancelledGross += $period->grossMinor;

                continue;
            }

            /** Straddles the effective date: part delivered, part owed back. */
            if ($period->covers($effectiveAt)) {
                $truncation = PeriodTruncation::of($period, $effectiveAt);
            }

            /** Entirely in the past and still scheduled: delivered, and F05 owes a recognition. */
        }

        return new self(RefundType::PRORATA, $effectiveAt, $cancelledIds, $cancelledGross, $truncation);
    }

    /**
     * Give back everything, including time that has been delivered and earned.
     *
     * No truncation: a full refund does not care where the effective date
     * falls, because nothing is kept. Every scheduled period is cancelled
     * whole, and the earnings that were already recognized are handled by the
     * clawback.
     *
     * @param list<PeriodLine> $periods
     */
    public static function forFull(array $periods, CarbonImmutable $effectiveAt): self
    {
        $cancelledIds = [];
        $cancelledGross = 0;

        foreach ($periods as $period) {
            if ($period->isScheduled()) {
                $cancelledIds[] = $period->id;
                $cancelledGross += $period->grossMinor;
            }
        }

        return new self(RefundType::FULL, $effectiveAt, $cancelledIds, $cancelledGross, null);
    }

    /**
     * What leaves `deferred_revenue` — time paid for and never delivered.
     *
     * For a pro-rata refund this is the whole refund. For a full one it is the
     * unearned half, and the clawback covers the rest.
     */
    public function unearnedMinor(): int
    {
        return $this->cancelledGrossMinor
            + ($this->truncation instanceof PeriodTruncation ? $this->truncation->unusedMinor : 0);
    }

    public function truncatesAPeriod(): bool
    {
        return $this->truncation instanceof PeriodTruncation;
    }

    /**
     * Whether this refund moves any money at all. A term that was already
     * fully recognized still gets a `refunds` row and a status change — the
     * fact happened — but nothing to post.
     */
    public function movesUnearnedMoney(): bool
    {
        return $this->unearnedMinor() > 0;
    }
}
