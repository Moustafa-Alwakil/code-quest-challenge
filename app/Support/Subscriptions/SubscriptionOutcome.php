<?php

declare(strict_types=1);

namespace App\Support\Subscriptions;

/**
 * What recording a payment did (F04).
 *
 * `replayed` is the interesting half: it says the payment was already on file,
 * so this call wrote nothing at all. Both outcomes are successes — the money was
 * recorded once, and that once may have happened a moment ago on another worker.
 */
final readonly class SubscriptionOutcome
{
    private function __construct(
        public int $subscriptionId,
        public bool $replayed,
    ) {}

    /**
     * This call created the subscription, the payment, the posting and the
     * schedule.
     */
    public static function recorded(int $subscriptionId): self
    {
        return new self($subscriptionId, false);
    }

    /**
     * The `external_ref` was already recorded; nothing was written.
     */
    public static function replayOf(int $subscriptionId): self
    {
        return new self($subscriptionId, true);
    }
}
