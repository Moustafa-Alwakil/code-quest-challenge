<?php

declare(strict_types=1);

namespace App\Support\Accrual;

use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One period of an accrual schedule: when it runs, and what it is worth (F04).
 *
 * Half-open `[periodStart, periodEnd)` — `periodEnd` is the next period's start,
 * so `days` counts each day exactly once across the whole term.
 */
final readonly class SchedulePeriod
{
    public function __construct(
        public int $sequence,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public int $days,
        public Money $gross,
    ) {}
}
