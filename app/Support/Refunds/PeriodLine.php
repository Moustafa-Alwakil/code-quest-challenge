<?php

declare(strict_types=1);

namespace App\Support\Refunds;

use App\Enums\AccrualPeriodStatus;
use Carbon\CarbonImmutable;

/**
 * One accrual period, as refund planning needs to see it (F09).
 *
 * A value object rather than the model, so the plan can be computed by a pure
 * function and tested against hand-written terms with no database at all —
 * which is what makes the boundary cases (refund on day one, refund exactly on
 * a period boundary, refund after the term ended) cheap to cover.
 */
final readonly class PeriodLine
{
    public function __construct(
        public int $id,
        public int $sequence,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public int $days,
        public int $grossMinor,
        public AccrualPeriodStatus $status,
    ) {}

    /**
     * Whether the effective date falls inside this half-open period.
     *
     * `[start, end)`, so a refund dated exactly on a boundary belongs to the
     * period that is starting, not the one that just closed — which is why
     * "refund exactly on a period boundary" truncates nothing.
     */
    public function covers(CarbonImmutable $effective): bool
    {
        return $effective->greaterThanOrEqualTo($this->periodStart)
            && $effective->lessThan($this->periodEnd);
    }

    public function isScheduled(): bool
    {
        return $this->status === AccrualPeriodStatus::SCHEDULED;
    }
}
