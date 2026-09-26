<?php

declare(strict_types=1);

use App\Models\MockProviderTransfer;
use App\Models\PayoutAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * The two tables F07 adds, read from MySQL's own catalogue.
 *
 * `mock_provider_transfers` is the one worth a schema test that might look
 * surprising: its UNIQUE `idempotency_key` is not our idempotency mechanism, it
 * is the *provider's*, modelled here so every retry test exercises the same
 * contract a real provider would enforce. Weaken it and the suite starts
 * proving that our retries are safe against a provider that has no dedup — a
 * much weaker claim than the one the docs make.
 */

it('creates the two tables F07 names', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['payout_attempts', 'mock_provider_transfers']);

it('keys a mock transfer by a unique idempotency key', function (): void {
    $unique = collect(Schema::getIndexes('mock_provider_transfers'))
        ->firstWhere('name', 'mock_provider_transfers_key_unique');

    expect($unique)->not->toBeNull()
        ->and((bool) $unique['unique'])->toBeTrue()
        ->and(array_values($unique['columns']))->toBe(['idempotency_key']);
});

it('refuses a second mock transfer for one key, in the database', function (): void {
    $attributes = [
        'idempotency_key' => '22222222-2222-4222-8222-222222222222',
        'account_ref' => 'acct_1',
        'amount_minor' => 10_000,
        'currency' => 'EGP',
        'status' => 'succeeded',
    ];

    MockProviderTransfer::query()->create($attributes);

    expect(fn () => MockProviderTransfer::query()->create($attributes))->toThrow(QueryException::class);

    expect(MockProviderTransfer::query()->count())->toBe(1);
});

it('has no updated_at on an attempt, because an attempt is never rewritten', function (): void {
    expect(Schema::hasColumn('payout_attempts', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('payout_attempts', 'created_at'))->toBeTrue();
});

it('numbers attempts per item, in order', function (): void {
    $attempt = PayoutAttempt::factory()->create();

    expect($attempt->attempt_no)->toBe(1);

    $index = collect(Schema::getIndexes('payout_attempts'))
        ->firstWhere('name', 'payout_attempts_item_index');

    expect($index)->not->toBeNull()
        ->and(array_values($index['columns']))->toBe(['payout_item_id', 'attempt_no']);
});
