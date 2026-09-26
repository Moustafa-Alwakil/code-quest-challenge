<?php

declare(strict_types=1);

use App\Enums\PayoutItemStatus;
use App\Models\PayoutAttempt;
use App\Models\PayoutItem;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * PLAN §8.2, the ordering rule, as a test rather than a comment:
 *
 *     commit the intent · call the provider · commit the outcome
 *
 * A transaction held across the network call either rolls a real transfer out
 * of the records, or pins a row lock for the provider's entire timeout. Both
 * are fatal at scale and neither shows up in any other assertion — the money
 * comes out right in the happy path either way.
 *
 * `ScriptedMockProvider` refuses to be called above the transaction depth it
 * was born at, so any future change that wraps the send in a transaction turns
 * this from a convention into a red suite.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('calls the provider with no transaction of ours open', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_ordering_0001');

    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    /** The provider throws if it is called inside a transaction we opened. */
    expect(processItem($item->id))->toBe(PayoutItemStatus::SUCCEEDED);
});

it('commits the intent before the call, so a crash leaves a durable submitted row', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_ordering_0002');

    /**
     * The provider is unreachable, so the action throws after the `submitted`
     * CAS. If that CAS shared a transaction with the send, this rollback would
     * take it with it and the item would be `reserved` — indistinguishable from
     * one nothing was ever attempted for.
     */
    provider()->script([ScriptedMockProvider::OUTCOME_UNAVAILABLE]);

    try {
        processItem($item->id);
    } catch (Throwable) {
        // The queue's problem, not this test's.
    }

    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::SUBMITTED)
        ->and($item->submitted_at)->not->toBeNull()
        ->and($item->next_check_at)->not->toBeNull()
        /** And the attempt is on record, which is how F08 knows to ask. */
        ->and(PayoutAttempt::query()->where('payout_item_id', $item->id)->count())->toBe(1);
});

it('records the outcome in its own transaction, separate from the call', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_ordering_0003');
    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    $levelDuringSettlement = null;

    DB::listen(function ($query) use (&$levelDuringSettlement): void {
        /** `insertOrIgnore` compiles to `insert ignore into`, so match the table. */
        if (str_contains($query->sql, 'ledger_entries') && str_contains($query->sql, 'insert')) {
            $levelDuringSettlement = DB::transactionLevel();
        }
    });

    processItem($item->id);

    /**
     * The settlement legs and the status change are one fact, so they *must*
     * share a transaction — the opposite requirement to the send, and the
     * reason the two cannot be the same transaction.
     */
    expect($levelDuringSettlement)->not->toBeNull()
        ->and($levelDuringSettlement)->toBeGreaterThan(DB::transactionLevel());
});

it('leaves a terminal item untouched when a late response arrives', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_ordering_0004');
    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    processItem($item->id);

    $settled = PayoutItem::query()->findOrFail($item->id);
    $attemptsAfterSettling = PayoutAttempt::query()->where('payout_item_id', $item->id)->count();

    /** A duplicate delivery arriving long after the item resolved. */
    expect(processItem($item->id))->toBe(PayoutItemStatus::SUCCEEDED);

    $item->refresh();

    expect($item->settled_at?->toDateTimeString())->toBe($settled->settled_at?->toDateTimeString())
        ->and($item->provider_reference)->toBe($settled->provider_reference)
        ->and(PayoutAttempt::query()->where('payout_item_id', $item->id)->count())->toBe($attemptsAfterSettling);
});
