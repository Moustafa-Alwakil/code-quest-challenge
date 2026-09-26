<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RefundType;
use App\Models\Refund;
use Carbon\CarbonImmutable;

/**
 * The refunds aggregate (F09).
 *
 * One row per subscription, and the database says so. A replayed webhook or a
 * re-run command finds the row on the way in and returns; a *concurrent*
 * duplicate gets past that check and dies on UNIQUE `subscription_id`, taking
 * its whole transaction — and every period it had started cancelling — back out
 * with it. The retry then takes the replay path.
 *
 * That is the same two-layer shape `SubscribeStudentAction` uses for payments,
 * and for the same reason: a read cannot see an uncommitted write, so the
 * index has to be the thing that decides.
 */
final class RefundService
{
    /**
     * The refund already on file for this term, if there is one.
     *
     * @return int|null the refund's id
     */
    public function idForSubscription(int $subscriptionId): ?int
    {
        $id = Refund::query()
            ->where('subscription_id', $subscriptionId)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function idForExternalRef(string $externalRef): ?int
    {
        $id = Refund::query()
            ->where('external_ref', $externalRef)
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Records the refund. A plain insert, deliberately: a concurrent duplicate
     * must hit the unique index and roll its transaction back rather than being
     * swallowed, or the loser would leave a term with cancelled periods and no
     * refund behind them.
     *
     * @return int the refund's id, which the postings are keyed by
     */
    public function record(
        int $subscriptionId,
        int $paymentId,
        RefundType $type,
        int $amountMinor,
        string $currency,
        CarbonImmutable $effectiveAt,
        string $externalRef,
        ?string $reason,
    ): int {
        return Refund::query()->create([
            'subscription_id' => $subscriptionId,
            'payment_id' => $paymentId,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'effective_at' => $effectiveAt->toDateString(),
            'external_ref' => $externalRef,
            'reason' => $reason,
        ])->id;
    }
}
