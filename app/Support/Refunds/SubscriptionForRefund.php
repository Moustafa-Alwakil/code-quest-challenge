<?php

declare(strict_types=1);

namespace App\Support\Refunds;

use App\Enums\SubscriptionStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The term a refund is about, read under its lock (F09).
 *
 * A value object rather than the model, so the Action never holds an Eloquent
 * instance. `price` is the amount snapshotted at purchase (F04), which is what
 * a full refund gives back — a later plan price change has nothing to do with
 * what this student paid.
 */
final readonly class SubscriptionForRefund
{
    public function __construct(
        public int $id,
        public SubscriptionStatus $status,
        public Money $price,
        public int $paymentId,
        public CarbonImmutable $termStart,
    ) {}

    /**
     * Whether this term has already been refunded, in which case the caller is
     * replaying and should write nothing.
     */
    public function isAlreadyRefunded(): bool
    {
        return $this->status === SubscriptionStatus::REFUNDED;
    }
}
