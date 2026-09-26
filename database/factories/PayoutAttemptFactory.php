<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutAttemptOperation;
use App\Models\PayoutAttempt;
use App\Models\PayoutItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutAttempt>
 */
final class PayoutAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payout_item_id' => PayoutItem::factory(),
            'attempt_no' => 1,
            'operation' => PayoutAttemptOperation::TRANSFER,
            'request' => 'transfer 10000 EGP',
            'response' => 'succeeded',
            'outcome' => 'succeeded',
            'duration_ms' => 12,
        ];
    }
}
