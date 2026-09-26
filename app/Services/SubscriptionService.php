<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The subscriptions aggregate, payments included (F04).
 *
 * One aggregate, not two: a payment is 1:1 with the term it bought (UNIQUE
 * `subscription_id`), and the two rows are always written together in one
 * transaction the Action owns.
 *
 * `recordPayment()` inserts plainly rather than with `insertOrIgnore`, and that
 * is the design: a concurrent duplicate must hit UNIQUE `external_ref` and take
 * its whole transaction down, so the retry sees the committed payment and
 * replays. Swallowing the violation here would leave the loser holding a
 * subscription with no payment behind it.
 */
final class SubscriptionService
{
    /**
     * The subscription a captured payment already paid for, if this
     * `external_ref` is on file.
     *
     * The replay check, and the reason a replay writes nothing at all.
     */
    public function subscriptionIdForExternalRef(string $externalRef): ?int
    {
        return Payment::query()
            ->where('external_ref', $externalRef)
            ->first(['subscription_id'])
            ?->subscription_id;
    }

    /**
     * @param Money $price snapshotted from the plan, so a later price change cannot reach it
     */
    public function createActive(
        int $userId,
        int $planId,
        CarbonImmutable $termStart,
        CarbonImmutable $termEnd,
        int $termDays,
        Money $price,
    ): int {
        return Subscription::query()->create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => SubscriptionStatus::ACTIVE,
            'term_start' => $termStart->toDateString(),
            'term_end' => $termEnd->toDateString(),
            'term_days' => $termDays,
            'price_minor' => $price->minor,
            'currency' => $price->currency,
        ])->id;
    }

    /**
     * @return int the payment's id, which the `payment_received` posting is keyed by
     */
    public function recordPayment(int $subscriptionId, string $externalRef, Money $amount, CarbonImmutable $capturedAt): int
    {
        return Payment::query()->create([
            'subscription_id' => $subscriptionId,
            'external_ref' => $externalRef,
            'amount_minor' => $amount->minor,
            'currency' => $amount->currency,
            'captured_at' => $capturedAt,
        ])->id;
    }

    /**
     * Closes off one bounded batch of finished terms.
     *
     * A conditional `UPDATE ... WHERE status = 'active'`, so it is idempotent by
     * construction: a row it has already expired no longer matches. The caller
     * loops until a batch comes back short, which keeps each transaction — and
     * each set of row locks — bounded however many terms ended overnight.
     *
     * `term_end` is exclusive, so a term ending today is over today.
     *
     * @return int rows expired by this batch
     */
    public function expireTermsEndedBy(CarbonImmutable $asOf, int $limit): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE)
            ->where('term_end', '<=', $asOf->toDateString())
            ->orderBy('id')
            ->limit($limit)
            ->update(['status' => SubscriptionStatus::EXPIRED]);
    }
}
