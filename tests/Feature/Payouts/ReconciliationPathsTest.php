<?php

declare(strict_types=1);

use App\Enums\PayoutItemStatus;
use App\Jobs\ProcessPayoutItemJob;
use App\Models\InstructorBalance;
use App\Models\PayoutAttempt;
use App\Models\PayoutItem;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/*
 * The answers that are not answers, and what F08 does with each.
 *
 * Every row here is a decision about somebody's money made on incomplete
 * information, and the shared thread is that none of them guesses: `unknown`
 * never becomes `failed` by the passage of time, and `not_found` is treated as
 * a lagging status API until it has had long enough not to be.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * An item the provider has no record of, because the transfer never reached it.
 */
function itemWithNoProviderRecord(string $externalRef): PayoutItem
{
    $item = reservedItemFor($externalRef);

    /**
     * The transfer provably never left, so the item is `submitted` with its key
     * and nothing exists on the provider's side — exactly the state a `not_found`
     * is asked about.
     */
    provider()->script([ScriptedMockProvider::OUTCOME_UNAVAILABLE]);

    try {
        processItem($item->id);
    } catch (Throwable) {
        // Rethrown for the queue's backoff; not this test's concern.
    }

    return $item->refresh();
}

it('waits rather than resending when a not-found is still inside the grace window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemWithNoProviderRecord('ch_notfound_0001');

    expect($item->status)->toBe(PayoutItemStatus::SUBMITTED);

    /** Fourteen minutes later: a status API can lag this much. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:14:00'));

    DB::table('payout_items')->where('id', $item->id)->update(['next_check_at' => CarbonImmutable::now()]);

    $this->artisan('payouts:reconcile', ['--sync' => true])->assertSuccessful();

    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::UNKNOWN)
        ->and($item->next_check_at)->not->toBeNull()
        /** Nothing was sent, so the provider still has no record. */
        ->and(provider()->transferCount($item->idempotency_key))->toBe(0)
        ->and(InstructorBalance::query()->findOrFail($item->instructor_id)->reserved_minor)
        ->toBe($item->amount_minor);
});

it('resends once with the same key when the grace window has passed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemWithNoProviderRecord('ch_notfound_0002');
    $originalKey = $item->idempotency_key;

    /** Sixteen minutes on: the provider has had its chance to see it. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:16:00'));

    DB::table('payout_items')->where('id', $item->id)->update(['next_check_at' => CarbonImmutable::now()]);

    $this->artisan('payouts:reconcile', ['--sync' => true])->assertSuccessful();

    $item->refresh();

    /**
     * F08's half: the item goes back to `reserved` and a send is queued. It
     * keeps the *same* idempotency key, which is the whole safety argument —
     * if the status API had been lying, the provider's dedup absorbs the
     * resend instead of paying twice.
     */
    expect($item->idempotency_key)->toBe($originalKey)
        ->and($item->status)->toBe(PayoutItemStatus::RESERVED);

    Bus::assertDispatched(ProcessPayoutItemJob::class);

    /** F07's half, once a worker picks it up. */
    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    expect(processItem($item->id))->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(provider()->transferCount($originalKey))->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($item->instructor_id)->paid_minor)
        ->toBe($item->refresh()->amount_minor);
});

it('hands an item nobody can resolve to a human, with the money still frozen', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_notfound_0003');

    /** Accepted, and never decided. */
    provider()->script([ScriptedMockProvider::OUTCOME_DELAYED_CONFIRMATION]);
    processItem($item->id);

    /** A day later, still pending as far as anyone can tell. */
    $this->travelTo(CarbonImmutable::parse('2026-09-16 09:30:00'));

    DB::table('payout_items')->where('id', $item->id)->update(['next_check_at' => CarbonImmutable::now()]);
    DB::table('mock_provider_transfers')->update(['confirm_after_checks' => 999]);

    $this->artisan('payouts:reconcile', ['--sync' => true])->assertSuccessful();

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    /**
     * `needs_review`, never `failed`. Twenty-four hours of silence says nothing
     * about whether the money moved, and releasing it back to `available` on
     * that basis is how an instructor gets paid twice.
     */
    expect($item->status)->toBe(PayoutItemStatus::NEEDS_REVIEW)
        ->and($item->last_error)->toContain('unresolved 24h')
        ->and($balance->reserved_minor)->toBe($item->amount_minor)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->paid_minor)->toBe(0);

    /** And the sweep leaves it alone from then on. */
    $this->artisan('payouts:reconcile', ['--sync' => true])
        ->expectsOutputToContain('found 0 uncertain')
        ->assertSuccessful();
});

it('re-dispatches a reserved item whose job was lost', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    /** `Bus::fake()` inside the helper is the lost job: reserved, never sent. */
    $item = reservedItemFor('ch_stranded_0001');

    expect($item->status)->toBe(PayoutItemStatus::RESERVED);

    /** Within half an hour it is not yet considered stranded. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:20:00'));

    $this->artisan('payouts:reconcile', ['--sync' => true])
        ->expectsOutputToContain('0 stranded')
        ->assertSuccessful();

    expect($item->refresh()->status)->toBe(PayoutItemStatus::RESERVED);

    /** Past half an hour, the sweep assumes the worker is never coming. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:40:00'));

    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    $this->artisan('payouts:reconcile', ['--sync' => true])
        ->expectsOutputToContain('1 stranded')
        ->assertSuccessful();

    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($item->instructor_id)->paid_minor)
        ->toBe($item->amount_minor);
});

it('changes nothing when reconciliation reaches an item that has already settled', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_late_0001');

    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);
    processItem($item->id);

    $settled = PayoutItem::query()->findOrFail($item->id);
    $attempts = PayoutAttempt::query()->where('payout_item_id', $item->id)->count();

    /** A sweep that was already in flight when the worker finished. */
    DB::table('payout_items')->where('id', $item->id)->update(['next_check_at' => CarbonImmutable::now()]);

    $this->artisan('payouts:reconcile', ['--sync' => true])->assertSuccessful();

    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and($item->settled_at?->toDateTimeString())->toBe($settled->settled_at?->toDateTimeString())
        ->and(PayoutAttempt::query()->where('payout_item_id', $item->id)->count())->toBe($attempts)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1);
});
