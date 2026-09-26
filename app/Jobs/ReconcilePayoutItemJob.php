<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Payouts\FlagPayoutItemForReviewAction;
use App\Actions\Payouts\ReconcilePayoutItemAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Asks the provider what happened to one uncertain payout (F08).
 *
 * Carries an id and nothing else (R15). `ShouldBeUnique` and
 * `WithoutOverlapping` keep two sweeps from asking about the same item at once,
 * and both are optimizations: if they failed, the compare-and-swap on the
 * item's status and the ledger's unique key would still admit exactly one
 * settlement.
 *
 * Its retries are short and few, because this job asks a question rather than
 * moving money — a failure to ask is cheap, and the sweep comes round again
 * every five minutes regardless.
 */
final class ReconcilePayoutItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        private int $payoutItemId,
    ) {
        $this->onQueue('payouts');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return (string) $this->payoutItemId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->payoutItemId))->expireAfter(180)];
    }

    public function handle(ReconcilePayoutItemAction $reconcilePayoutItem): void
    {
        $reconcilePayoutItem($this->payoutItemId);
    }

    /**
     * We could not even ask. The item keeps its money reserved and goes to a
     * human — the same destination as an item the provider refuses to resolve,
     * because from the money's point of view they are the same situation.
     */
    public function failed(?Throwable $exception): void
    {
        app(FlagPayoutItemForReviewAction::class)(
            $this->payoutItemId,
            'reconciliation failed: '.($exception?->getMessage() ?? 'no exception given'),
        );
    }
}
