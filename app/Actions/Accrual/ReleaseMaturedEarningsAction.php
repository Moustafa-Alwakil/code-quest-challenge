<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

use App\DTOs\Accrual\ReleaseMaturedEarningsData;
use App\Services\EarningAllocationService;
use App\Services\InstructorBalanceService;
use App\Support\Ledger\BalanceDelta;
use Illuminate\Support\Facades\DB;

/**
 * Moves earnings whose hold has expired from `held` to `available` (D-6, F05).
 *
 * Runs at the end of every `ledger:accrue` and at the start of every
 * `payouts:run`, so availability is current at the two moments it decides
 * anything.
 *
 * **This is the one action that applies balance deltas without posting a ledger
 * entry**, because a release is not a ledger fact (R2). The money was already
 * owed at recognition; the hold only ever controlled when it became payable,
 * and that state lives on the allocation row. Posting a second pair of entries
 * per allocation would add tens of millions of rows carrying information
 * `released_at` already carries.
 *
 * Idempotent without a lock: `released_at IS NULL` is the guard, so a row this
 * run has released no longer matches the next run's WHERE. The `FOR UPDATE`
 * inside the query only stops two concurrent sweeps duplicating the work.
 */
final class ReleaseMaturedEarningsAction
{
    public function __construct(
        private EarningAllocationService $allocations,
        private InstructorBalanceService $balances,
    ) {}

    /**
     * @return int allocations released by this run
     */
    public function __invoke(ReleaseMaturedEarningsData $data): int
    {
        $released = 0;

        do {
            $batch = DB::transaction(fn (): int => $this->releaseBatch($data));

            $released += $batch;
        } while ($batch === $data->chunkSize);

        return $released;
    }

    /**
     * One bounded batch, one transaction.
     *
     * Bounded rather than a single sweeping `UPDATE`: at 500k subscriptions a
     * day's matured allocations are in the tens of thousands, and holding that
     * many row locks in one transaction would block every concurrent
     * recognition touching the same instructors. A crash mid-sweep leaves the
     * batches before it committed and the rest still matching the WHERE.
     *
     * The totals are read back from the rows this batch just locked, rather
     * than summed in PHP from what was selected: the database is the thing that
     * decides what was actually released.
     */
    private function releaseBatch(ReleaseMaturedEarningsData $data): int
    {
        $ids = $this->allocations->lockMaturedIds($data->asOf, $data->chunkSize);

        if ($ids === []) {
            return 0;
        }

        $totals = $this->allocations->totalsByInstructor($ids);

        $released = $this->allocations->markReleased($ids, $data->asOf);

        $deltas = [];

        foreach ($totals as $instructorId => $amountMinor) {
            $deltas[] = BalanceDelta::released($instructorId, $amountMinor);
        }

        /**
         * The watermark stays 0 on purpose. `last_ledger_entry_id` is written
         * as `greatest(existing, n)` and is a debugging aid nothing may branch
         * on (R19); a release has no entry id to offer, and claiming one would
         * be worse than claiming none.
         */
        $this->balances->applyDeltas($data->currency, 0, ...$deltas);

        return $released;
    }
}
