<?php

declare(strict_types=1);

use App\Models\AccrualPeriod;
use App\Models\Instructor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LedgerPostings;

/*
 * The schema *is* the idempotency mechanism (D-10). `LedgerService::post()`
 * decides "new posting" versus "replay" purely from the affected-row count of
 * an `insertOrIgnore`, so the unique index is not a safety net around the
 * logic — it is the logic. A migration edit that renames it, widens it or makes
 * one of its columns nullable does not break a unit test; it silently turns
 * every replay into a double payment.
 *
 * These tests assert the shape directly, against MySQL's own catalogue, so that
 * kind of regression fails by name.
 */

/**
 * @return array<string, array{nullable: bool, type: string, default: string|null}>
 */
function columnCatalogue(string $table): array
{
    /** @var list<object{COLUMN_NAME: string, IS_NULLABLE: string, COLUMN_TYPE: string, COLUMN_DEFAULT: string|null, EXTRA: string}> $rows */
    $rows = DB::select(
        'select column_name as COLUMN_NAME, is_nullable as IS_NULLABLE, column_type as COLUMN_TYPE,
                column_default as COLUMN_DEFAULT, extra as EXTRA
         from information_schema.columns
         where table_schema = database() and table_name = ?',
        [$table],
    );

    $catalogue = [];

    foreach ($rows as $row) {
        $catalogue[$row->COLUMN_NAME] = [
            'nullable' => $row->IS_NULLABLE === 'YES',
            'type' => $row->COLUMN_TYPE,
            'default' => $row->COLUMN_DEFAULT,
        ];
    }

    return $catalogue;
}

/**
 * @return list<string> the indexed columns, in index order
 */
function indexColumns(string $table, string $index): array
{
    /** @var list<object{COLUMN_NAME: string}> $rows */
    $rows = DB::select(
        'select column_name as COLUMN_NAME from information_schema.statistics
         where table_schema = database() and table_name = ? and index_name = ?
         order by seq_in_index',
        [$table, $index],
    );

    return array_map(static fn (object $row): string => $row->COLUMN_NAME, $rows);
}

it('declares each ledger table exactly once, with no duplicated column', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();

    $columns = array_keys(columnCatalogue($table));

    expect($columns)->toBe(array_values(array_unique($columns)));
})->with(['ledger_entries', 'instructor_balances']);

it('keeps the idempotency key on exactly the five columns F03 names, in order', function (): void {
    expect(indexColumns('ledger_entries', 'ledger_entries_idempotency_unique'))
        ->toBe(['entry_type', 'reference_type', 'reference_id', 'account_type', 'account_id']);
});

it('makes every column of the idempotency key NOT NULL (R4, the NULL gotcha)', function (): void {
    $columns = columnCatalogue('ledger_entries');

    foreach (['entry_type', 'reference_type', 'reference_id', 'account_type', 'account_id'] as $column) {
        expect($columns[$column]['nullable'])
            ->toBeFalse("{$column} is nullable, which silently disables the whole unique key in MySQL.");
    }
});

it('indexes per-account history for the balance recomputation', function (): void {
    expect(indexColumns('ledger_entries', 'ledger_entries_account_index'))
        ->toBe(['account_type', 'account_id', 'id'])
        ->and(indexColumns('ledger_entries', 'ledger_entries_transaction_index'))
        ->toBe(['transaction_uuid']);
});

it('stores ledger amounts as signed bigint minor units, never decimal or float', function (): void {
    $columns = columnCatalogue('ledger_entries');

    expect($columns['amount_minor']['type'])->toBe('bigint')
        ->and($columns['currency']['type'])->toBe('char(3)')
        ->and($columns['account_id']['type'])->toBe('bigint unsigned')
        ->and($columns['reference_id']['type'])->toBe('bigint unsigned');
});

it('has no updated_at on the ledger, so a row cannot be dated twice', function (): void {
    expect(Schema::hasColumn('ledger_entries', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('ledger_entries', 'created_at'))->toBeTrue();
});

it('lets available_minor go negative and keeps every money column signed (D-7)', function (): void {
    $columns = columnCatalogue('instructor_balances');

    foreach ([
        'earned_minor',
        'clawed_back_minor',
        'held_minor',
        'available_minor',
        'reserved_minor',
        'paid_minor',
    ] as $column) {
        expect($columns[$column]['type'])
            ->toBe('bigint', "{$column} must be signed: a clawback beyond the balance carries forward (D-7).");
    }
});

it('keys the snapshot by instructor id alone', function (): void {
    expect(indexColumns('instructor_balances', 'PRIMARY'))->toBe(['instructor_id']);
});

it('stamps the snapshot updated_at in PHP, because no model ever writes the row', function (): void {
    /**
     * `InstructorBalanceService` only ever issues `UPDATE ... SET x = x + ?`,
     * and nothing goes through Eloquent, so nothing would touch the timestamp
     * on its own. The column therefore carries no database-side default (R50)
     * and the service stamps it — on the insert of a zero row and on every
     * increment. The catalogue half of this asserts the default is gone; the
     * behavioural half asserts the stamp actually happens, because a column
     * with neither is silently null forever.
     */
    /** @var list<object{EXTRA: string, COLUMN_DEFAULT: string|null}> $rows */
    $rows = DB::select(
        "select extra as EXTRA, column_default as COLUMN_DEFAULT from information_schema.columns
         where table_schema = database() and table_name = 'instructor_balances' and column_name = 'updated_at'",
    );

    expect($rows)->toHaveCount(1)
        ->and(mb_strtolower($rows[0]->EXTRA))->not->toContain('on update current_timestamp')
        ->and($rows[0]->COLUMN_DEFAULT)->toBeNull();

    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
    );

    expect(DB::table('instructor_balances')->where('instructor_id', $instructor->id)->value('updated_at'))
        ->not->toBeNull();
});

it('cascades the snapshot away with its instructor', function (): void {
    /** @var list<object{DELETE_RULE: string, TABLE_NAME: string}> $rows */
    $rows = DB::select(
        "select rc.delete_rule as DELETE_RULE, kcu.referenced_table_name as TABLE_NAME
         from information_schema.referential_constraints rc
         join information_schema.key_column_usage kcu
           on kcu.constraint_name = rc.constraint_name and kcu.constraint_schema = rc.constraint_schema
         where rc.constraint_schema = database() and rc.table_name = 'instructor_balances'",
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->DELETE_RULE)->toBe('CASCADE')
        ->and($rows[0]->TABLE_NAME)->toBe('instructors');
});

/*
 * R22, R49: the dates this application does arithmetic on are cast
 * `immutable_date` / `immutable_datetime` on the model, so every
 * `@property CarbonImmutable` annotation is true. Without the cast Eloquent
 * hands back a mutable Illuminate\Support\Carbon and PHPStan — which trusts
 * the annotation — reports nothing when F04's period boundary maths mutates an
 * attribute in place.
 *
 * Bookkeeping timestamps are deliberately not in that set: nothing reads
 * `created_at` as a date, so they keep the framework's own type rather than
 * carrying a cast for a guarantee no caller needs. The one exception is
 * `payout_items.created_at`, which F08's stranded sweep measures from (R35).
 */

it('hands back immutable dates from the columns the money maths reads', function (): void {
    $period = AccrualPeriod::factory()->create();

    expect($period->period_start)->toBeInstanceOf(CarbonImmutable::class)
        ->and($period->period_end)->toBeInstanceOf(CarbonImmutable::class);

    $anchor = $period->period_start;
    $period->period_start->addMonths(3);

    expect($period->period_start->equalTo($anchor))->toBeTrue();
});
