<?php

declare(strict_types=1);

namespace App\Support\Accrual;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The facts recognition needs about one period, read after the compare-and-set
 * has already claimed it (F05).
 *
 * A value object rather than a model: the Action may not touch Eloquent, and
 * everything here is read once inside the recognizing transaction and never
 * written back through.
 */
final readonly class PeriodForRecognition
{
    public function __construct(
        public int $periodId,
        public int $subscriptionId,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public Money $gross,
    ) {}

    /**
     * When this period's earnings stop being held and become payable (D-6).
     *
     * Measured from `period_end`, not from the moment of recognition: the hold
     * protects against a refund of delivered time, and a backfilled run must
     * not push an instructor's money further away than a timely one would have.
     */
    public function availableAt(int $holdDays): CarbonImmutable
    {
        return $this->periodEnd->addDays($holdDays);
    }
}
