<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Engagement>
 *
 * A rollup row for one instructor in one period. Weights are 1-600 minutes,
 * matching F02's generation rule; a test that cares about the split states the
 * units explicitly rather than trusting the range.
 */
final class EngagementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'period_start' => CarbonImmutable::now()->startOfDay()->toDateString(),
            'instructor_id' => Instructor::factory(),
            'units' => $this->faker->numberBetween(1, 600),
        ];
    }

    /**
     * The zero-engagement case is the *absence* of rows, not a row of zero
     * (D-3): recognition filters on `units > 0`, so a zero row would prove
     * nothing the missing row does not already prove. This state exists to test
     * that the filter is actually there.
     */
    public function dormant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'units' => 0,
        ]);
    }
}
