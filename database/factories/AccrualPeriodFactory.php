<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccrualPeriodStatus;
use App\Models\AccrualPeriod;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccrualPeriod>
 *
 * A single scheduled period, for tests about one period in isolation. A real
 * schedule is written by AccrualService from an AccrualSchedule, because only
 * that guarantees Σ gross = price (invariant I8) — a set of factory periods
 * satisfies no such thing.
 */
final class AccrualPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = CarbonImmutable::now()->startOfDay();
        $periodEnd = $periodStart->addMonthNoOverflow();

        return [
            'subscription_id' => Subscription::factory(),
            'sequence' => 1,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'days' => (int) $periodStart->diffInDays($periodEnd),
            'gross_minor' => 30_000,
            'status' => AccrualPeriodStatus::SCHEDULED,
        ];
    }

    public function recognized(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AccrualPeriodStatus::RECOGNIZED,
            'recognized_at' => CarbonImmutable::now(),
        ]);
    }
}
