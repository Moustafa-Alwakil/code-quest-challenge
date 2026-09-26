<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * The schema is the idempotency mechanism (D-10, PLAN §9 rows 5-8), so these
 * read MySQL's own catalogue rather than trusting the migration file.
 *
 * Widening either unique index, or making a key column nullable, breaks no unit
 * test — it silently lets a second invocation open a second run, or a retried
 * job send a second transfer. The last two tests prove the database really
 * refuses the write rather than merely declaring that it would.
 */

/**
 * @return array{columns: list<string>, unique: bool}
 */
function payoutIndex(string $table, string $name): array
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
function payoutColumn(string $table, string $name): array
{
    foreach (Schema::getColumns($table) as $column) {
        if ($column['name'] === $name) {
            return ['type' => $column['type_name'], 'nullable' => (bool) $column['nullable']];
        }
    }

    throw new RuntimeException("Column {$name} does not exist on {$table}.");
}

it('creates the two tables F06 names', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['payout_runs', 'payout_items']);

it('keys a run by a NOT NULL unique run_key', function (): void {
    expect(payoutIndex('payout_runs', 'payout_runs_key_unique'))
        ->toBe(['columns' => ['run_key'], 'unique' => true])
        ->and(payoutColumn('payout_runs', 'run_key')['nullable'])->toBeFalse();
});

it('allows one item per instructor per run, and one idempotency key overall', function (): void {
    expect(payoutIndex('payout_items', 'payout_items_run_instructor_unique'))
        ->toBe(['columns' => ['payout_run_id', 'instructor_id'], 'unique' => true])
        ->and(payoutIndex('payout_items', 'payout_items_idempotency_unique'))
        ->toBe(['columns' => ['idempotency_key'], 'unique' => true])
        /** A nullable column inside a unique key disables the whole guarantee (R4). */
        ->and(payoutColumn('payout_items', 'idempotency_key')['nullable'])->toBeFalse();
});

it('indexes the reconciliation sweep by status first', function (): void {
    expect(payoutIndex('payout_items', 'payout_items_reconcile_index'))
        ->toBe(['columns' => ['status', 'next_check_at'], 'unique' => false]);
});

it('stores money as signed bigint minor units, never a decimal', function (array $column): void {
    [$table, $name] = $column;

    expect(payoutColumn($table, $name)['type'])->toBe('bigint');
})->with([
    [['payout_runs', 'total_minor']],
    [['payout_items', 'amount_minor']],
]);

it('refuses a second item for one instructor in one run, in the database', function (): void {
    $run = PayoutRun::factory()->create();
    $instructor = Instructor::factory()->create();

    PayoutItem::factory()->for($run)->for($instructor)->create();

    expect(fn () => PayoutItem::factory()->for($run)->for($instructor)->create())
        ->toThrow(QueryException::class);

    expect(PayoutItem::query()->count())->toBe(1);
});

it('refuses a second item with the same idempotency key, in the database', function (): void {
    $first = PayoutItem::factory()->create(['idempotency_key' => '11111111-1111-4111-8111-111111111111']);

    expect(fn () => PayoutItem::factory()->create(['idempotency_key' => $first->idempotency_key]))
        ->toThrow(QueryException::class);

    expect(PayoutItem::query()->count())->toBe(1);
});
