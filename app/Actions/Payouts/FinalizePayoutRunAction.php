<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Services\PayoutRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes a run once its items have stopped moving (F06).
 *
 * Called from the dispatch batch's `finally` when F07 wires the workers in, and
 * re-evaluated by F08 as `unknown` items resolve — which is why it is a
 * compare-and-swap from the status it expects rather than a blind write. A
 * reconciliation that resolves the last unknown item finds the run
 * `completed_with_pending` and moves it to `completed`; a second call finds
 * nothing to move and says so.
 *
 * The distinction it draws is D-8's: a run holding an `unknown` item has not
 * failed and has not finished. Calling that `completed` would claim an outcome
 * the provider has not given.
 */
final class FinalizePayoutRunAction
{
    public function __construct(
        private PayoutRunService $payoutRuns,
    ) {}

    /**
     * @return PayoutRunStatus the status the run now holds
     */
    public function __invoke(int $payoutRunId): PayoutRunStatus
    {
        return DB::transaction(function () use ($payoutRunId): PayoutRunStatus {
            $counts = $this->payoutRuns->itemStatusCounts($payoutRunId);

            $target = self::statusFor($counts);

            $run = $this->payoutRuns->find($payoutRunId);

            if ($run === null || $run->status === $target) {
                return $target;
            }

            $this->payoutRuns->transition(
                $payoutRunId,
                $run->status,
                $target,
                $target->isFinished() ? CarbonImmutable::now() : null,
            );

            return $target;
        });
    }

    /**
     * An item still `reserved` means nobody has tried to send it yet, so the
     * run is not finished in any sense — it is waiting for workers, not for the
     * provider. That is `dispatched`, not `completed_with_pending`: the latter
     * says "we sent everything and are waiting to hear", which would be a lie.
     *
     * @param array<string, int> $counts
     */
    private static function statusFor(array $counts): PayoutRunStatus
    {
        if ($counts === []) {
            return PayoutRunStatus::COMPLETED;
        }

        if (($counts[PayoutItemStatus::RESERVED->value] ?? 0) > 0) {
            return PayoutRunStatus::DISPATCHED;
        }

        foreach ([PayoutItemStatus::SUBMITTED, PayoutItemStatus::UNKNOWN, PayoutItemStatus::NEEDS_REVIEW] as $pending) {
            if (($counts[$pending->value] ?? 0) > 0) {
                return PayoutRunStatus::COMPLETED_WITH_PENDING;
            }
        }

        return PayoutRunStatus::COMPLETED;
    }
}
