<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\DTOs\Subscriptions\ExpireSubscriptionsData;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;

/**
 * Marks finished terms `expired` (F04).
 *
 * **Cosmetic only.** Money is driven by `accrual_periods`, never by a
 * subscription's status, so this sweep failing for a week costs nobody a
 * piastre — it only means the admin screen reads `active` for a term that ended.
 * That separation is deliberate: a status column that money depended on would
 * make a missed cron job a financial incident.
 *
 * Bounded batches rather than one unbounded `UPDATE`: each batch is its own
 * transaction, so a sweep over half a million rows never holds an unbounded
 * number of row locks, and a crash mid-sweep leaves committed work behind it.
 * Each batch is idempotent — an expired row no longer matches the WHERE.
 */
final class ExpireSubscriptionsAction
{
    public function __construct(
        private SubscriptionService $subscriptions,
    ) {}

    /**
     * @return int subscriptions expired by this run
     */
    public function __invoke(ExpireSubscriptionsData $data): int
    {
        $expired = 0;

        do {
            $batch = DB::transaction(
                fn (): int => $this->subscriptions->expireTermsEndedBy($data->asOf, $data->chunkSize),
            );

            $expired += $batch;
        } while ($batch === $data->chunkSize);

        return $expired;
    }
}
