<?php

declare(strict_types=1);

namespace App\Support\Payouts;

use App\Enums\PayoutItemStatus;

/**
 * What an Action needs to know about one payout item (F07).
 *
 * A value object rather than the model, for the same reason as
 * `PayoutRunSnapshot`: an Action may not touch Eloquent, and handing it a model
 * would put a live query surface into the use-case layer.
 *
 * `accountRef` comes along because the provider needs it and the Action is not
 * allowed to go and fetch it.
 */
final readonly class PayoutItemSnapshot
{
    public function __construct(
        public int $id,
        public int $payoutRunId,
        public int $instructorId,
        public int $amountMinor,
        public string $currency,
        public PayoutItemStatus $status,
        public string $idempotencyKey,
        public string $accountRef,
        public int $attempts,
    ) {}
}
