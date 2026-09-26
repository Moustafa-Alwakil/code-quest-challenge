<?php

declare(strict_types=1);

use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * The schema is the idempotency mechanism (D-10, R4), exactly as it is for the
 * ledger. `SubscribeStudentAction` decides "new payment" versus "replay" from a
 * SELECT, and a concurrent duplicate is stopped by UNIQUE `external_ref` alone.
 * Widening that index, renaming it or making the column nullable breaks no unit
 * test — it silently turns a double-recorded payment into a second subscription,
 * a second liability and a second schedule.
 *
 * These assertions read MySQL's own catalogue, and the last two prove the
 * database really refuses the write rather than merely declaring that it would.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * @return array{columns: list<string>, unique: bool}
 */
function subscriptionIndex(string $table, string $name): array
{
    foreach (Schema::getIndexes($table) as $index) {
        if ($index['name'] === $name) {
            return ['columns' => array_values($index['columns']), 'unique' => (bool) $index['unique']];
        }
    }

    throw new RuntimeException("Index {$name} does not exist on {$table}.");
}

/**
 * @return array{type: string, nullable: bool}
 */
function subscriptionColumn(string $table, string $name): array
{
    foreach (Schema::getColumns($table) as $column) {
        if ($column['name'] === $name) {
            return ['type' => $column['type_name'], 'nullable' => (bool) $column['nullable']];
        }
    }

    throw new RuntimeException("Column {$name} does not exist on {$table}.");
}

it('creates the three tables F04 names', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['subscriptions', 'payments', 'accrual_periods']);

it('keys a payment by a NOT NULL unique external_ref', function (): void {
    expect(subscriptionIndex('payments', 'payments_external_ref_unique'))
        ->toBe(['columns' => ['external_ref'], 'unique' => true])
        /** A nullable column in a unique key disables the whole guarantee (R4). */
        ->and(subscriptionColumn('payments', 'external_ref')['nullable'])->toBeFalse();
});

it('allows one up-front payment per subscription', function (): void {
    expect(subscriptionIndex('payments', 'payments_subscription_unique'))
        ->toBe(['columns' => ['subscription_id'], 'unique' => true])
        ->and(subscriptionColumn('payments', 'subscription_id')['nullable'])->toBeFalse();
});

it('keys an accrual period by sequence and by start date', function (): void {
    expect(subscriptionIndex('accrual_periods', 'accrual_periods_sequence_unique'))
        ->toBe(['columns' => ['subscription_id', 'sequence'], 'unique' => true])
        ->and(subscriptionIndex('accrual_periods', 'accrual_periods_start_unique'))
        ->toBe(['columns' => ['subscription_id', 'period_start'], 'unique' => true]);
});

it('indexes the two sweeps by status first', function (): void {
    expect(subscriptionIndex('subscriptions', 'subscriptions_expiry_index'))
        ->toBe(['columns' => ['status', 'term_end'], 'unique' => false])
        ->and(subscriptionIndex('accrual_periods', 'accrual_periods_recognition_index'))
        ->toBe(['columns' => ['status', 'period_end'], 'unique' => false]);
});

it('stores money as signed bigint minor units, never a decimal', function (array $column): void {
    [$table, $name] = $column;

    expect(subscriptionColumn($table, $name)['type'])->toBe('bigint');
})->with([
    [['subscriptions', 'price_minor']],
    [['payments', 'amount_minor']],
    [['accrual_periods', 'gross_minor']],
    [['accrual_periods', 'pool_minor']],
    [['accrual_periods', 'platform_minor']],
]);

it('leaves the recognition columns nullable and the scheduled ones not', function (): void {
    expect(subscriptionColumn('accrual_periods', 'pool_minor')['nullable'])->toBeTrue()
        ->and(subscriptionColumn('accrual_periods', 'platform_minor')['nullable'])->toBeTrue()
        ->and(subscriptionColumn('accrual_periods', 'recognized_at')['nullable'])->toBeTrue()
        ->and(subscriptionColumn('accrual_periods', 'gross_minor')['nullable'])->toBeFalse()
        ->and(subscriptionColumn('accrual_periods', 'status')['nullable'])->toBeFalse();
});

it('keeps term and period boundaries as dates', function (array $column): void {
    [$table, $name] = $column;

    expect(subscriptionColumn($table, $name)['type'])->toBe('date');
})->with([
    [['subscriptions', 'term_start']],
    [['subscriptions', 'term_end']],
    [['accrual_periods', 'period_start']],
    [['accrual_periods', 'period_end']],
]);

it('refuses a second payment with the same external_ref, in the database', function (): void {
    $first = Subscription::factory()->create();
    $second = Subscription::factory()->create();

    Payment::factory()->for($first)->create(['external_ref' => 'ch_live_0001']);

    expect(fn () => Payment::factory()->for($second)->create(['external_ref' => 'ch_live_0001']))
        ->toThrow(QueryException::class);

    expect(Payment::query()->count())->toBe(1);
});

it('refuses a second period with the same sequence, in the database', function (): void {
    $subscription = Subscription::factory()->create();

    $subscription->accrualPeriods()->create([
        'sequence' => 1,
        'period_start' => '2024-01-31',
        'period_end' => '2024-02-29',
        'days' => 29,
        'gross_minor' => 25_000,
        'status' => 'scheduled',
    ]);

    expect(fn () => $subscription->accrualPeriods()->create([
        'sequence' => 1,
        'period_start' => '2024-03-31',
        'period_end' => '2024-04-30',
        'days' => 30,
        'gross_minor' => 25_000,
        'status' => 'scheduled',
    ]))->toThrow(QueryException::class);
});
