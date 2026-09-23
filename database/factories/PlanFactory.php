<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::lower(Str::random(10)),
            'name' => 'Monthly',
            'interval_months' => 1,
            'price_minor' => 30_000,
            'currency' => config('revenue.currency'),
            'is_active' => true,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'key' => 'monthly',
            'name' => 'Monthly',
            'interval_months' => 1,
            'price_minor' => 30_000,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'key' => 'quarterly',
            'name' => 'Quarterly',
            'interval_months' => 3,
            'price_minor' => 80_000,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'key' => 'annual',
            'name' => 'Annual',
            'interval_months' => 12,
            'price_minor' => 300_000,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
