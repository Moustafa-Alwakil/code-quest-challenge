<?php

declare(strict_types=1);

use App\Exceptions\LedgerIntegrityException;
use App\Services\LedgerService;
use Tests\Support\LedgerPostings;
use Tests\TestCase;

/*
 * Rule 1 of posting: a posting commits with the business state change it
 * records, so LedgerService refuses to write outside an open transaction.
 *
 * This lives in the unit suite deliberately. RefreshDatabase holds a
 * transaction open for the whole of a feature test, so `transactionLevel()` is
 * never 0 there and the guard could not be proven. Booting the application
 * without it gives the real condition. That these tests pass at all is the
 * second half of the proof: the unit suite has no migrated schema, so a guard
 * that let a single query through would fail with a missing table.
 */
uses(TestCase::class);

it('refuses to post outside an open database transaction', function (): void {
    $transaction = LedgerPostings::recognition(
        periodId: 1,
        subscriptionId: 1,
        instructorId: 1,
        grossMinor: 10_000,
        instructorMinor: 7_000,
    );

    expect(fn () => app(LedgerService::class)->post($transaction))
        ->toThrow(LedgerIntegrityException::class, 'outside a database transaction')
        ->and(DB::transactionLevel())->toBe(0);
});

it('names the transaction it refused, so the log says which posting was dropped', function (): void {
    $transaction = LedgerPostings::reservation(payoutItemId: 88, instructorId: 4, amountMinor: 5_000);

    expect(fn () => app(LedgerService::class)->post($transaction))
        ->toThrow(LedgerIntegrityException::class, 'payout_reserved for payout_item#88');
});
