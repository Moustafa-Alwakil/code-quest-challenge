<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

use App\DTOs\Accrual\AccrueRevenueData;
use App\Jobs\AccruePeriodsChunkJob;
use App\Services\AccrualService;
use App\Support\Accrual\AccrualRunSummary;
use Illuminate\Support\Facades\Bus;

/**
 * The daily sweep: every period whose term has closed becomes revenue (F05).
 *
 * Opens no transaction of its own — each period's recognition owns one, which
 * is what keeps a sweep over half a million subscriptions from holding a single
 * unbounded lock set. The trade-off is stated openly: one transaction per
 * period is the simplest thing to reason about and the easiest to prove
 * idempotent; one per chunk with bulk inserts would be faster. Periods close on
 * each subscription's monthly anniversary, so 500k subscriptions spread to
 * ~17k recognitions a day rather than 500k on the 1st, and the simpler shape is
 * affordable.
 *
 * Iteration is keyset (`WHERE id > ?`), never OFFSET: recognition changes
 * `status` under the cursor, which is precisely the case OFFSET skips rows on.
 *
 * The batch is dispatched here rather than in the command or a Service. A
 * Service may not reach for `Bus` and a command may not reach past an Action —
 * the use case layer is the only one allowed to hold both ends.
 */
final class AccrueRevenueAction
{
    public function __construct(
        private AccrualService $accrual,
        private RecognizeAccrualPeriodAction $recognizeAccrualPeriod,
        private ReleaseMaturedEarningsAction $releaseMaturedEarnings,
    ) {}

    public function __invoke(AccrueRevenueData $data): AccrualRunSummary
    {
        $found = 0;
        $dispatched = 0;
        $recognized = 0;
        $skipped = 0;
        $allocations = 0;

        /** @var list<AccruePeriodsChunkJob> $jobs */
        $jobs = [];

        $afterId = 0;

        while (true) {
            $periodIds = $this->accrual->dueScheduledPeriodIds($data->asOf, $afterId, $data->chunkSize);

            if ($periodIds === []) {
                break;
            }

            $afterId = $periodIds[count($periodIds) - 1];
            $found += count($periodIds);

            if (! $data->sync) {
                $jobs[] = $this->chunkJob($data, $periodIds);
                $dispatched += count($periodIds);

                continue;
            }

            foreach ($periodIds as $periodId) {
                $outcome = ($this->recognizeAccrualPeriod)($data->forPeriod($periodId));

                $outcome->recognized ? $recognized++ : $skipped++;
                $allocations += $outcome->allocationCount;
            }
        }

        if ($jobs !== []) {
            Bus::batch($jobs)->name('ledger:accrue '.$data->asOf->toDateString())->dispatch();
        }

        /**
         * The release runs last, and runs whether or not anything was
         * recognized: holds mature on the calendar, not on this run's output.
         * In queued mode it releases what earlier runs recognized, since this
         * run's own allocations are still being written by workers — and they
         * are held for `hold_days` anyway, so there is nothing of theirs to
         * release yet.
         */
        $released = ($this->releaseMaturedEarnings)($data->release());

        return AccrualRunSummary::of($found, $dispatched, $recognized, $skipped, $allocations, $released);
    }

    /**
     * @param list<int> $periodIds
     */
    private function chunkJob(AccrueRevenueData $data, array $periodIds): AccruePeriodsChunkJob
    {
        $job = new AccruePeriodsChunkJob(
            $periodIds,
            $data->instructorShareBps,
            $data->holdDays,
            $data->currency,
            $data->zeroEngagementPolicy->value,
            $data->recognizedAt->toIso8601String(),
        );

        /** A worker must never observe state this run has not committed yet. */
        return $job->afterCommit();
    }
}
