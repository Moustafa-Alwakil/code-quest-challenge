<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayoutRunStatus;
use App\Models\PayoutRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PayoutRun>
 *
 * For tests about a run in isolation. A run that has actually reserved
 * anything comes from `payouts:run`, because only that writes the ledger
 * entries the items imply — a factory run with factory items fails
 * `ledger:verify`, which is the correct behaviour (F02, Factories).
 */
final class PayoutRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_key' => 'payout:'.Str::lower(Str::random(10)),
            'scheduled_for' => CarbonImmutable::now()->toDateString(),
            'status' => PayoutRunStatus::OPEN,
            'currency' => 'EGP',
            'started_at' => CarbonImmutable::now(),
        ];
    }

    public function dispatched(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutRunStatus::DISPATCHED,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutRunStatus::COMPLETED,
            'finished_at' => CarbonImmutable::now(),
        ]);
    }

    public function completedWithPending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PayoutRunStatus::COMPLETED_WITH_PENDING,
            'finished_at' => CarbonImmutable::now(),
        ]);
    }
}
