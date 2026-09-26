<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutItemStatus;
use App\Models\Instructor;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PayoutItem>
 *
 * Born `reserved`, like the real thing: there is no `pending` state, because
 * creating an item and reserving its money happen in one transaction.
 */
final class PayoutItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payout_run_id' => PayoutRun::factory(),
            'instructor_id' => Instructor::factory(),
            'amount_minor' => $this->faker->numberBetween(10_000, 500_000),
            'currency' => 'EGP',
            'status' => PayoutItemStatus::RESERVED,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutItemStatus::SUBMITTED,
            'attempts' => 1,
            'submitted_at' => CarbonImmutable::now(),
            'next_check_at' => CarbonImmutable::now()->addMinutes(10),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutItemStatus::SUCCEEDED,
            'attempts' => 1,
            'submitted_at' => CarbonImmutable::now(),
            'settled_at' => CarbonImmutable::now(),
            'provider_reference' => 'tr_'.Str::lower(Str::random(16)),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutItemStatus::FAILED,
            'attempts' => 1,
            'submitted_at' => CarbonImmutable::now(),
            'settled_at' => CarbonImmutable::now(),
            'last_error' => 'account_closed',
        ]);
    }

    /**
     * The state D-8 exists for: the provider was asked, and did not say.
     */
    public function unknown(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutItemStatus::UNKNOWN,
            'attempts' => 1,
            'submitted_at' => CarbonImmutable::now(),
            'next_check_at' => CarbonImmutable::now()->addMinutes(10),
        ]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutItemStatus::NEEDS_REVIEW,
            'attempts' => 5,
            'last_error' => 'exhausted retries',
        ]);
    }
}
