<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefundType;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 *
 * For tests about the row itself. A refund with consequences comes from
 * `refunds:issue`, because only that cancels the periods and posts the entries
 * the row implies — a factory refund leaves a term whose deferred revenue never
 * came back, which `ledger:verify` reports (F02, Factories).
 */
final class RefundFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'payment_id' => Payment::factory(),
            'type' => RefundType::PRORATA,
            'amount_minor' => 10_000,
            'currency' => 'EGP',
            'effective_at' => CarbonImmutable::now()->toDateString(),
            'external_ref' => 're_'.Str::lower(Str::random(24)),
            'reason' => null,
        ];
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => RefundType::FULL,
        ]);
    }
}
