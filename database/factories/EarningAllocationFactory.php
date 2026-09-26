<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EarningAllocation>
 *
 * For tests about the hold in isolation. A factory-made allocation has no
 * ledger entries and no snapshot behind it, so any test that asserts on
 * balances recognizes a real period instead — `ledger:verify` will say so
 * otherwise, which is the correct behaviour.
 */
final class EarningAllocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'accrual_period_id' => AccrualPeriod::factory(),
            'instructor_id' => Instructor::factory(),
            'weight_units' => $this->faker->numberBetween(1, 600),
            'amount_minor' => $this->faker->numberBetween(100, 100_000),
            'currency' => 'EGP',
            'available_at' => CarbonImmutable::now()->addDays(7),
        ];
    }

    /**
     * Past its hold, but not yet swept.
     */
    public function matured(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'available_at' => CarbonImmutable::now()->subDays(2),
            'released_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    public function clawedBack(): static
    {
        return $this->state(fn (array $attributes): array => [
            'clawed_back_at' => CarbonImmutable::now(),
        ]);
    }
}
