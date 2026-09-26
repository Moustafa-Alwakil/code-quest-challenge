<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Money;
use App\Support\Refunds\SubscriptionForRefund;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

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
     * The term a refund is about, locked for the caller's transaction (F09).
     *
     * The lock is the outermost one a refund takes, and every other lock in the
     * system is taken after it — instructor balances ascending, periods by id.
     * One order everywhere is what keeps a refund and a concurrent payout run
     * from deadlocking on the same instructor.
     */
    public function lockForRefund(int $subscriptionId): ?SubscriptionForRefund
    {
        return $this->readForRefund($subscriptionId, locking: true);
    }

    /**
     * The same read without the lock, for `--dry-run`.
     *
     * A preview that took row locks would block a concurrent recognition for
     * as long as someone was looking at the output, which is a strange price to
     * pay for a number nobody is acting on yet.
     */
    public function findForRefund(int $subscriptionId): ?SubscriptionForRefund
    {
        return $this->readForRefund($subscriptionId, locking: false);
    }

    /**
     * Marks a term refunded, from either state a live term can be in.
     *
     * `expired` is admitted as well as `active` because a term whose last
     * period has closed can still be refunded — the pro-rata amount is simply
     * zero, and F09's edge-case table says the status should still move. The
     * CAS excludes `refunded`, which is what makes a second refund a no-op
     * rather than a second cancellation.
     *
     * @return bool true when this call refunded the term
     */
    public function markRefunded(int $subscriptionId, CarbonImmutable $canceledAt): bool
    {
        $moved = Subscription::query()
            ->where('id', $subscriptionId)
            ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::EXPIRED])
            ->update([
                'status' => SubscriptionStatus::REFUNDED,
                'canceled_at' => $canceledAt,
            ]);

        return $moved === 1;
    }

    /**
     * The next page of subscription ids, for a verification run that has to
     * visit every term.
     *
     * Keyset (`WHERE id > ?`), never OFFSET: the set is large and a run that
     * re-counted from the start on every page would be quadratic.
     *
     * @return list<int> ascending
     */
    public function idsAfter(int $afterId, int $limit): array
    {
        /** @var list<int> $ids */
        $ids = Subscription::query()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return $ids;
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

    private static function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Expected a numeric column from subscriptions, got '.get_debug_type($value).'.');
        }

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a string column from subscriptions, got '.get_debug_type($value).'.');
        }

        return $value;
    }

    private function readForRefund(int $subscriptionId, bool $locking): ?SubscriptionForRefund
    {
        $row = Subscription::query()
            ->join('payments', 'payments.subscription_id', '=', 'subscriptions.id')
            ->where('subscriptions.id', $subscriptionId)
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->first([
                'subscriptions.id',
                'subscriptions.status',
                'subscriptions.price_minor',
                'subscriptions.currency',
                'subscriptions.term_start',
                'payments.id as payment_id',
            ]);

        if ($row === null) {
            return null;
        }

        return new SubscriptionForRefund(
            $subscriptionId,
            $row->status,
            Money::of(
                self::asInt($row->getAttribute('price_minor')),
                self::asString($row->getAttribute('currency')),
            ),
            self::asInt($row->getAttribute('payment_id')),
            $row->term_start,
        );
    }
}
