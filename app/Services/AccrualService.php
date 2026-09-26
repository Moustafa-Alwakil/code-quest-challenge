<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccrualPeriodStatus;
use App\Support\Accrual\AccrualSchedule;
use App\Support\Accrual\SchedulePeriod;
use Illuminate\Support\Facades\DB;

/**
 * The `accrual_periods` aggregate (F04, extended by F05's recognition).
 *
 * Writing a schedule is an `insertOrIgnore` against UNIQUE
 * `(subscription_id, sequence)` and `(subscription_id, period_start)`, so
 * re-running it is a no-op — the same guarantee the ledger gets from its own
 * unique key, and for the same reason: the database decides, not PHP.
 *
 * `pool_minor` and `platform_minor` are deliberately left out of the insert.
 * They are null until F05 recognizes the period.
 */
final class AccrualService
{
    /**
     * One multi-row `insertOrIgnore`, not a chunk loop: a term is at most
     * `AccrualSchedule::MAX_INTERVAL_MONTHS` periods, so a second chunk is a
     * branch no run could ever take. If a later caller inserts periods for many
     * subscriptions in one statement, the chunking belongs in that method, where
     * it can actually iterate.
     *
     * @return int periods written — 0 when the schedule was already on file
     */
    public function scheduleFor(int $subscriptionId, AccrualSchedule $schedule): int
    {
        $rows = array_map(static fn (SchedulePeriod $period): array => [
            'subscription_id' => $subscriptionId,
            'sequence' => $period->sequence,
            'period_start' => $period->periodStart->toDateString(),
            'period_end' => $period->periodEnd->toDateString(),
            'days' => $period->days,
            'gross_minor' => $period->gross->minor,
            'status' => AccrualPeriodStatus::SCHEDULED->value,
        ], $schedule->periods);

        return DB::table('accrual_periods')->insertOrIgnore($rows);
    }
}
