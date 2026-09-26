<?php

declare(strict_types=1);

namespace App\Support\Subscriptions;

use App\Support\Accrual\AccrualSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One term to be written as part of a bulk ingestion (F02's `ScaleSeeder`).
 *
 * Everything `SubscribeStudentAction` would compute for a single term, computed
 * in advance so fifty thousand of them can be written in a handful of
 * statements instead of fifty thousand transactions.
 *
 * The schedule is carried rather than derived later, because it is the
 * expensive part — anchor arithmetic and a largest-remainder split per term —
 * and doing it once, here, keeps the write path to pure inserts.
 */
final readonly class BulkTerm
{
    public function __construct(
        public int $userId,
        public int $planId,
        public string $externalRef,
        public Money $price,
        public CarbonImmutable $capturedAt,
        public AccrualSchedule $schedule,
    ) {}
}
