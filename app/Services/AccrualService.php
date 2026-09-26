<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccrualPeriodStatus;
use App\Models\AccrualPeriod;
use App\Support\Accrual\AccrualSchedule;
use App\Support\Accrual\PeriodForRecognition;
use App\Support\Accrual\SchedulePeriod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

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
    private const DEFAULT_CHUNK_SIZE = 1000;

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

    /**
     * The next page of periods whose term has closed and which nobody has
     * recognized yet (F05 step 2).
     *
     * Keyset (`WHERE id > ?`), never OFFSET: a sweep over half a million
     * periods must not re-count rows it has already passed, and recognition
     * changes `status` under the cursor, which is exactly the case OFFSET gets
     * wrong. Hits `accrual_periods_recognition_index (status, period_end)`.
     *
     * `period_end` is exclusive, so a period ending on the run date is over on
     * the run date and is due.
     *
     * @return list<int> ascending
     */
    public function dueScheduledPeriodIds(CarbonImmutable $asOf, int $afterId, int $limit): array
    {
        /** @var list<int> $ids */
        $ids = AccrualPeriod::query()
            ->where('status', AccrualPeriodStatus::SCHEDULED)
            ->where('period_end', '<=', $asOf->toDateString())
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * The compare-and-set that claims a period for this run (F05 step 1).
     *
     * This is the whole concurrency story. Two servers sweeping at once both
     * issue this UPDATE; MySQL serializes them on the row, and exactly one sees
     * an affected count of 1. The loser recognizes nothing and writes nothing.
     *
     * There is no intermediate `recognizing` status (R3): this UPDATE and every
     * write that follows share the caller's transaction, so a crash takes the
     * status back with it and the next run picks the period up. A separate
     * state would be stranded by exactly the crash it was invented to survive.
     *
     * @return bool true when this call claimed the period; false when it was
     *              already recognized, or cancelled by a refund
     */
    public function markRecognized(int $periodId, CarbonImmutable $recognizedAt): bool
    {
        $claimed = AccrualPeriod::query()
            ->where('id', $periodId)
            ->where('status', AccrualPeriodStatus::SCHEDULED)
            ->update([
                'status' => AccrualPeriodStatus::RECOGNIZED,
                'recognized_at' => $recognizedAt,
            ]);

        return $claimed === 1;
    }

    /**
     * What the claimed period is worth, and which window it covers.
     *
     * Read after the CAS, inside the same transaction: before it, the row could
     * still be claimed by someone else, and the currency comes from the
     * subscription's snapshotted price rather than from config, so a plan
     * repriced in another currency could never rewrite an existing term.
     */
    public function findForRecognition(int $periodId): ?PeriodForRecognition
    {
        $row = AccrualPeriod::query()
            ->join('subscriptions', 'subscriptions.id', '=', 'accrual_periods.subscription_id')
            ->where('accrual_periods.id', $periodId)
            ->first([
                'accrual_periods.id',
                'accrual_periods.subscription_id',
                'accrual_periods.period_start',
                'accrual_periods.period_end',
                'accrual_periods.gross_minor',
                'subscriptions.currency',
            ]);

        if ($row === null) {
            return null;
        }

        return new PeriodForRecognition(
            $periodId,
            self::asInt($row->getAttribute('subscription_id')),
            CarbonImmutable::parse(self::asString($row->getAttribute('period_start'))),
            CarbonImmutable::parse(self::asString($row->getAttribute('period_end'))),
            Money::of(
                self::asInt($row->getAttribute('gross_minor')),
                self::asString($row->getAttribute('currency')),
            ),
        );
    }

    /**
     * Records how the gross was divided (F05 step 8).
     *
     * A separate statement from the CAS because it carries different
     * information: the CAS decides *who* recognizes the period, this records
     * *what they decided*. Both commit together, so a period can never be
     * `recognized` with a null split.
     */
    public function storeSplit(int $periodId, int $poolMinor, int $platformMinor): void
    {
        AccrualPeriod::query()
            ->where('id', $periodId)
            ->update([
                'pool_minor' => $poolMinor,
                'platform_minor' => $platformMinor,
            ]);
    }

    /**
     * Σ gross of the periods each of these subscriptions has not yet delivered
     * — everything still `scheduled` (verify check 5).
     *
     * This is what `deferred_revenue[sub]` is *supposed* to equal at every
     * moment, not only once the term is over: the liability is the undelivered
     * time, and recognition moves a period out of this sum and out of that
     * balance in the same transaction. Cancelled periods are excluded because a
     * refund discharges their liability with its own posting.
     *
     * A subscription with nothing left scheduled is absent from the result; the
     * caller reads that as the zero it is.
     *
     * @param  list<int>       $subscriptionIds
     * @return array<int, int> subscription id => undelivered gross in minor units
     */
    public function unrecognizedGrossFor(array $subscriptionIds): array
    {
        if ($subscriptionIds === []) {
            return [];
        }

        $gross = [];

        $rows = AccrualPeriod::query()
            ->selectRaw('subscription_id, sum(gross_minor) as gross_minor')
            ->where('status', AccrualPeriodStatus::SCHEDULED)
            ->whereIn('subscription_id', $subscriptionIds)
            ->groupBy('subscription_id')
            ->get();

        foreach ($rows as $row) {
            $gross[self::asInt($row->getAttribute('subscription_id'))] = self::asInt($row->getAttribute('gross_minor'));
        }

        return $gross;
    }

    /**
     * The next page of recognized periods with the split they recorded, for the
     * check that `platform + Σ allocations === gross` (invariant I6).
     *
     * @return array<int, array{gross: int, platform: int}> period id => amounts, ascending
     */
    public function recognizedSplits(int $afterId, int $limit = self::DEFAULT_CHUNK_SIZE): array
    {
        $splits = [];

        $rows = AccrualPeriod::query()
            ->where('status', AccrualPeriodStatus::RECOGNIZED)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'gross_minor', 'platform_minor']);

        foreach ($rows as $row) {
            $splits[$row->id] = [
                'gross' => $row->gross_minor,
                'platform' => $row->platform_minor ?? 0,
            ];
        }

        return $splits;
    }

    private static function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Expected a numeric column from accrual_periods, got '.get_debug_type($value).'.');
        }

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->toDateString();
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a string column from accrual_periods, got '.get_debug_type($value).'.');
        }

        return $value;
    }
}
