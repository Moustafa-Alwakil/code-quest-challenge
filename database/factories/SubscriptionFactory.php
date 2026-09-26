<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 *
 * For tests about the subscription row itself — the expiry sweep, a Filament
 * screen. A factory-made subscription has **no** payment, no ledger entries and
 * no accrual schedule, which is correct for those tests and wrong for any test
 * that asserts on money: those create subscriptions through
 * SubscribeStudentAction, so the ledger is born consistent (F02 rule).
 */
final class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $termStart = CarbonImmutable::now()->startOfDay();

        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::ACTIVE,
            'term_start' => $termStart->toDateString(),
            'term_end' => $termStart->addMonthNoOverflow()->toDateString(),
            'term_days' => (int) $termStart->diffInDays($termStart->addMonthNoOverflow()),
            'price_minor' => 30_000,
            'currency' => 'EGP',
        ];
    }

    /**
     * A term that ended `$daysAgo` days ago — what the expiry sweep looks for.
     */
    public function endedDaysAgo(int $daysAgo): static
    {
        return $this->state(function (array $attributes) use ($daysAgo): array {
            $termEnd = CarbonImmutable::now()->startOfDay()->subDays($daysAgo);
            $termStart = $termEnd->subMonthNoOverflow();

            return [
                'term_start' => $termStart->toDateString(),
                'term_end' => $termEnd->toDateString(),
                'term_days' => (int) $termStart->diffInDays($termEnd),
            ];
        });
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubscriptionStatus::EXPIRED,
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SubscriptionStatus::REFUNDED,
            'canceled_at' => CarbonImmutable::now(),
        ]);
    }
}
