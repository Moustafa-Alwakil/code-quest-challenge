<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\DTOs\Payouts\ReconcilePayoutsData;
use App\Jobs\ProcessPayoutItemJob;
use App\Jobs\ReconcilePayoutItemJob;
use App\Services\PayoutItemService;
use App\Support\Payouts\ReconciliationSchedule;
use App\Support\Payouts\ReconciliationSummary;

/**
 * The sweep behind `payouts:reconcile` (F08).
 *
 * Two passes over INDEX `(status, next_check_at)`, for the two ways a payout
 * can stall:
 *
 * - **uncertain** — we sent it and the provider has not settled it. Ask again.
 * - **stranded** — we reserved it and nobody ever sent it, because the job was
 *   lost. Send it.
 *
 * Re-dispatching a stranded item is safe rather than hopeful: it is still
 * `reserved`, so the compare-and-swap admits one worker, and the idempotency
 * key it has carried since reservation is the one the provider dedups on. That
 * is the same pair of guarantees the original dispatch relied on, which is why
 * this needs no special path.
 *
 * Opens no transaction: each item's resolution owns its own.
 */
final class ReconcilePayoutsAction
{
    public function __construct(
        private PayoutItemService $payoutItems,
        private ReconcilePayoutItemAction $reconcilePayoutItem,
        private ProcessPayoutItemAction $processPayoutItem,
    ) {}

    public function __invoke(ReconcilePayoutsData $data): ReconciliationSummary
    {
        $uncertain = $this->payoutItems->dueForReconciliation($data->asOf, $data->limit);

        $stranded = $this->payoutItems->strandedReserved(
            ReconciliationSchedule::strandedBefore($data->asOf),
            $data->limit,
        );

        if (! $data->sync) {
            foreach ($uncertain as $itemId) {
                ReconcilePayoutItemJob::dispatch($itemId)->afterCommit();
            }

            foreach ($stranded as $itemId) {
                ProcessPayoutItemJob::dispatch($itemId)->afterCommit();
            }

            return ReconciliationSummary::of(count($uncertain), count($stranded), 0, false);
        }

        $resolved = 0;

        foreach ($uncertain as $itemId) {
            if (($this->reconcilePayoutItem)($itemId)->isTerminal()) {
                $resolved++;
            }
        }

        foreach ($stranded as $itemId) {
            if (($this->processPayoutItem)($itemId)->isTerminal()) {
                $resolved++;
            }
        }

        return ReconciliationSummary::of(count($uncertain), count($stranded), $resolved, true);
    }
}
