<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Payouts\FlagPayoutItemForReviewAction;
use App\Actions\Payouts\ProcessPayoutItemAction;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Sends one reserved payout (F07).
 *
 * Carries an id and nothing else (R15): the Action reloads the item, so a job
 * sitting in the queue across a deploy cannot act on a stale amount or a status
 * that has since moved.
 *
 * **`ShouldBeUnique` and `WithoutOverlapping` are optimizations.** They stop two
 * copies of this job doing the same work; they do not stop two copies *paying
 * twice*, and nothing here relies on them for that. Three things do: the
 * `reserved → submitted` compare-and-swap, the item's `idempotency_key`, and
 * the provider's dedup on it. With the cache gone, all three still hold.
 */
final class ProcessPayoutItemJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable;
    use Queueable;

    /**
     * Five attempts over roughly twenty minutes, then `failed()` parks it.
     * Only a provider we could not reach gets this far — every outcome we can
     * interpret is terminal or `unknown` on the first pass.
     */
    public int $tries = 5;

    public function __construct(
        private int $payoutItemId,
    ) {
        $this->onQueue('payouts');
    }

    /**
     * Jittered so a provider coming back up is not hit by every worker at once.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
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

    public function handle(ProcessPayoutItemAction $processPayoutItem): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $processPayoutItem($this->payoutItemId);
    }

    /**
     * The retries are exhausted, or something threw that we cannot interpret.
     *
     * The item goes to `needs_review` and **the money stays reserved**. Never
     * back to `reserved` — that would make it dispatchable again, and we have
     * no evidence the transfer did not happen. Never to `failed` either: that
     * returns the balance to `available`, which is the same mistake in the
     * other direction. A human decides, with the audit trail in front of them.
     */
    public function failed(?Throwable $exception): void
    {
        app(FlagPayoutItemForReviewAction::class)(
            $this->payoutItemId,
            $exception?->getMessage() ?? 'job failed without an exception',
        );
    }
}
