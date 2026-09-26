<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Actions\Accrual\ReleaseMaturedEarningsAction;
use App\DTOs\Payouts\ReserveInstructorBalanceData;
use App\DTOs\Payouts\RunPayoutsData;
use App\Enums\PayoutRunStatus;
use App\Services\PayoutRunService;
use App\Support\Payouts\PayoutRunSnapshot;
use App\Support\Payouts\PayoutRunSummary;

/**
 * `payouts:run` — decide who is paid how much, and move that money out of
 * `available` before anything is sent (F06).
 *
 * Opens no transaction of its own: each reservation owns one, so a run over
 * half a million balances never holds an unbounded lock set, and an invocation
 * that dies halfway leaves every reservation it completed committed. The resume
 * is the same command with the same key.
 *
 * Idempotent at two levels, neither of them a lock:
 *
 * - the run is found by UNIQUE `run_key`, so a second invocation resumes rather
 *   than opening a second run;
 * - each instructor's item is `insertOrIgnore` against UNIQUE
 *   `(run, instructor)`, so a second invocation reserves nothing new.
 *
 * A *completed* run is not resumed at all. Its money has been sent; re-opening
 * it to reserve newly matured earnings would silently mix two periods' payouts
 * under one key, so new earnings wait for a new key — including on a manual
 * re-trigger.
 */
final class RunPayoutsAction
{
    public function __construct(
        private PayoutRunService $payoutRuns,
        private ReleaseMaturedEarningsAction $releaseMaturedEarnings,
        private ReserveInstructorBalanceAction $reserveInstructorBalance,
        private FinalizePayoutRunAction $finalizePayoutRun,
    ) {}

    public function __invoke(RunPayoutsData $data): PayoutRunSummary
    {
        /**
         * Step 1 — holds that matured since the last run become payable, so the
         * decision below is made against current availability (F05, D-6).
         * Skipped entirely by `--dry-run`, which must write nothing at all.
         */
        $released = $data->dryRun ? 0 : ($this->releaseMaturedEarnings)($data->release());

        if ($data->dryRun) {
            return $this->preview($data, $released);
        }

        /** Step 2 — the run for this key: created, or the one already on file. */
        $this->payoutRuns->createIfAbsent($data->runKey, $data->scheduledFor, $data->startedAt, $data->currency);

        $run = $this->payoutRuns->findByKey($data->runKey);

        if (! $run instanceof PayoutRunSnapshot) {
            return PayoutRunSummary::of(null, $data->runKey, PayoutRunStatus::OPEN, $released, 0, 0, 0, 0, 0);
        }

        if ($run->status->isFinished()) {
            return PayoutRunSummary::of(
                $run->id,
                $run->runKey,
                $run->status,
                $released,
                0,
                0,
                0,
                0,
                0,
                alreadyFinished: true,
            );
        }

        /** Step 3 — reserve, instructor by instructor, each in its own transaction. */
        [$considered, $reserved, $reservedMinor, $skipped] = $this->reserveEligibleBalances($data, $run->id);

        /**
         * Step 4 — every item still `reserved`, including ones a previous
         * crashed invocation left behind.
         *
         * F07 dispatches `ProcessPayoutItemJob` for exactly this list, in a
         * `Bus::batch()` whose `finally` calls `FinalizePayoutRunAction`. Until
         * then the run stops here with its items durable and its money already
         * out of `available` — which is the half that had to be right first.
         */
        $dispatchable = $this->payoutRuns->dispatchableItemIds($run->id);

        $status = ($this->finalizePayoutRun)($run->id);

        return PayoutRunSummary::of(
            $run->id,
            $run->runKey,
            $status,
            $released,
            $considered,
            $reserved,
            $reservedMinor,
            $skipped,
            count($dispatchable),
        );
    }

    /**
     * Keyset over the balances worth paying, reserving each.
     *
     * The cursor advances on the instructor id rather than on a page offset,
     * because reservation changes `available_minor` under the cursor — the
     * exact case OFFSET skips rows on.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function reserveEligibleBalances(RunPayoutsData $data, int $runId): array
    {
        $considered = 0;
        $reserved = 0;
        $reservedMinor = 0;
        $skipped = 0;

        $afterInstructorId = 0;

        while (true) {
            $balances = $this->payoutRuns->payableBalances(
                $data->minimumAmountMinor,
                $afterInstructorId,
                $data->chunkSize,
            );

            if ($balances === []) {
                return [$considered, $reserved, $reservedMinor, $skipped];
            }

            foreach ($balances as $instructorId => $availableMinor) {
                $afterInstructorId = $instructorId;
                $considered++;

                $outcome = ($this->reserveInstructorBalance)(ReserveInstructorBalanceData::forInstructor(
                    $runId,
                    $instructorId,
                    $data->minimumAmountMinor,
                    $data->currency,
                ));

                if ($outcome->reserved) {
                    $reserved++;
                    $reservedMinor += $outcome->amountMinor;

                    continue;
                }

                $skipped++;
            }
        }
    }

    /**
     * `--dry-run` — what this run would reserve, read from the same balances
     * the real path reserves from, and writing nothing.
     *
     * Deliberately *not* run through `ReserveInstructorBalanceAction` with a
     * rollback: a preview that takes row locks and writes ledger entries it
     * then discards is not a preview, and "no writes at all" is the property
     * the operator is relying on.
     */
    private function preview(RunPayoutsData $data, int $released): PayoutRunSummary
    {
        $considered = 0;
        $total = 0;
        $afterInstructorId = 0;

        while (true) {
            $balances = $this->payoutRuns->payableBalances(
                $data->minimumAmountMinor,
                $afterInstructorId,
                $data->chunkSize,
            );

            if ($balances === []) {
                break;
            }

            foreach ($balances as $instructorId => $availableMinor) {
                $afterInstructorId = $instructorId;
                $considered++;
                $total += $availableMinor;
            }
        }

        $existing = $this->payoutRuns->findByKey($data->runKey);

        return PayoutRunSummary::of(
            $existing?->id,
            $data->runKey,
            $existing->status ?? PayoutRunStatus::OPEN,
            $released,
            $considered,
            $considered,
            $total,
            0,
            0,
            dryRun: true,
        );
    }
}
