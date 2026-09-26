<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\RefundType;
use Carbon\CarbonImmutable;

/**
 * The refunds aggregate: one row per subscription, and the database says so.
 *
 * `record()` inserts plainly rather than ignoring duplicates, deliberately — a
 * concurrent duplicate must hit the unique index and take its whole transaction
 * back out, or the loser would leave a term with cancelled periods and no
 * refund behind them.
 */
interface RefundServiceContract
{
    /**
     * The refund already on file for this term, if there is one.
     *
     * @return int|null the refund's id
     */
    public function idForSubscription(int $subscriptionId): ?int;

    public function idForExternalRef(string $externalRef): ?int;

    /**
     * The refunds recorded against these terms, if any (verify check 7).
     *
     * @param  list<int>                                        $subscriptionIds
     * @return array<int, array{type: RefundType, amount: int}>
     */
    public function forSubscriptions(array $subscriptionIds): array;

    /**
     * Records the refund. A plain insert, deliberately: a concurrent duplicate
     * must hit the unique index and roll its transaction back rather than being
     * swallowed, or the loser would leave a term with cancelled periods and no
     * refund behind them.
     *
     * @return int the refund's id, which the postings are keyed by
     */
    public function record(int $subscriptionId, int $paymentId, RefundType $type, int $amountMinor, string $currency, CarbonImmutable $effectiveAt, string $externalRef, ?string $reason): int;
}
