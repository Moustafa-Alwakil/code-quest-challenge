<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The three subscription plans.
 *
 * Idempotent by `key`, so running it twice leaves the same three rows. Prices
 * are chosen to produce non-trivial rounding when split across their periods
 * and again across instructors (D-5): EGP 300 over 1 month, EGP 800 over 3,
 * EGP 3 000 over 12 — none of which divide evenly.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $currency = config('revenue.currency');

        $plans = [
            [
                'key' => 'monthly',
                'name' => 'Monthly',
                'interval_months' => 1,
                'price_minor' => 30_000,
            ],
            [
                'key' => 'quarterly',
                'name' => 'Quarterly',
                'interval_months' => 3,
                'price_minor' => 80_000,
            ],
            [
                'key' => 'annual',
                'name' => 'Annual',
                'interval_months' => 12,
                'price_minor' => 300_000,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()
                ->updateOrCreate(
                    attributes: [
                        'key' => $plan['key'],
                    ],
                    values: [
                        ...$plan,
                        'currency' => $currency,
                    ],
                );
        }
    }
}
