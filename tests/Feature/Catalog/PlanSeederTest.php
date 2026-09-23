<?php

declare(strict_types=1);

use App\Models\Plan;
use Database\Seeders\PlanSeeder;

it('seeds the three plans', function (): void {
    $this->seed(PlanSeeder::class);

    expect(Plan::pluck('price_minor', 'key')->all())->toBe([
        'monthly' => 30_000,
        'quarterly' => 80_000,
        'annual' => 300_000,
    ]);
});

it('is idempotent: running it twice leaves the same three rows', function (): void {
    $this->seed(PlanSeeder::class);
    $first = Plan::orderBy('key')->get(['id', 'key', 'price_minor']);

    $this->seed(PlanSeeder::class);
    $second = Plan::orderBy('key')->get(['id', 'key', 'price_minor']);

    expect(Plan::count())->toBe(3)
        ->and($second->toArray())->toBe($first->toArray());
});
