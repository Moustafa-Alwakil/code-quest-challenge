<?php

declare(strict_types=1);

use App\Models\Refund;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/*
 * Both unique indexes on `refunds`, read from MySQL's own catalogue.
 *
 * They guard different things. `subscription_id` is the business rule — one
 * refund per term, so a second attempt cancels nothing twice. `external_ref` is
 * the gateway's id, so a replayed webhook is harmless even if it arrives for a
 * term that has somehow not been marked yet. Widening either breaks no unit
 * test and silently allows a term to be refunded twice.
 */

it('creates the refunds table', function (): void {
    expect(Schema::hasTable('refunds'))->toBeTrue();
});

it('allows one refund per subscription and one per gateway reference', function (string $index, string $column): void {
    $found = collect(Schema::getIndexes('refunds'))->firstWhere('name', $index);

    expect($found)->not->toBeNull()
        ->and((bool) $found['unique'])->toBeTrue()
        ->and(array_values($found['columns']))->toBe([$column]);
})->with([
    'per subscription' => ['refunds_subscription_unique', 'subscription_id'],
    'per gateway reference' => ['refunds_external_ref_unique', 'external_ref'],
]);

it('keeps the gateway reference NOT NULL, or the index is disabled', function (): void {
    $column = collect(Schema::getColumns('refunds'))->firstWhere('name', 'external_ref');

    /** MySQL treats NULLs as distinct, so a nullable key column is no key at all (R4). */
    expect((bool) $column['nullable'])->toBeFalse();
});

it('stores the refund as signed bigint minor units', function (): void {
    $column = collect(Schema::getColumns('refunds'))->firstWhere('name', 'amount_minor');

    expect($column['type_name'])->toBe('bigint');
});

it('refuses a second refund for one subscription, in the database', function (): void {
    $subscription = Subscription::factory()->create();

    Refund::factory()->for($subscription)->create(['external_ref' => 're_first']);

    expect(fn () => Refund::factory()->for($subscription)->create(['external_ref' => 're_second']))
        ->toThrow(QueryException::class);

    expect(Refund::query()->count())->toBe(1);
});

it('refuses a second refund with the same gateway reference, in the database', function (): void {
    Refund::factory()->create(['external_ref' => 're_shared']);

    expect(fn () => Refund::factory()->create(['external_ref' => 're_shared']))
        ->toThrow(QueryException::class);

    expect(Refund::query()->count())->toBe(1);
});
