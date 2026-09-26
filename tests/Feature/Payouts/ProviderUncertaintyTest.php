<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Enums\PayoutAttemptOperation;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutAttempt;
use App\Models\PayoutRun;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;

/*
 * **Required proof #3**: unreliable provider responses never cause duplicate
 * payments.
 *
 * The scenario the whole design exists for. The provider moves the money and
 * then the connection dies, so we are left holding a transfer that may or may
 * not have happened. Guessing "failed" pays the instructor twice later;
 * guessing "succeeded" records a payment that never happened. The only correct
 * move is to say `unknown`, freeze the money, and ask again.
 *
 * What makes it a proof rather than a demonstration: `transferCount()` is the
 * provider's own count of times it moved money, and it stays at one across the
 * timeout, the reconciliation and everything afterwards.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('resolves a timed-out transfer by asking, and pays exactly once', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_uncertain_0001');

    /** The money moves, and the answer is lost on the way back. */
    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    processItem($item->id);
    $item->refresh();

    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($item->status)->toBe(PayoutItemStatus::UNKNOWN)
        ->and($balance->reserved_minor)->toBe($item->amount_minor)
        ->and($balance->paid_minor)->toBe(0)
        /** Nothing is recorded, because nothing is known. */
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(0);

    /** The sweep comes round and asks. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:05:00'));

    $this->artisan('payouts:reconcile', ['--sync' => true])
        ->expectsOutputToContain('found 1 uncertain')
        ->assertSuccessful();

    $item->refresh();
    $balance->refresh();

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and($item->settled_at)->not->toBeNull()
        ->and($item->next_check_at)->toBeNull()
        ->and($balance->paid_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0)
        /** The provider moved this money once, and only once. */
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1);

    /** Two interactions, one transfer — the line the video says out loud. */
    $attempts = PayoutAttempt::query()->where('payout_item_id', $item->id)->orderBy('attempt_no')->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->operation)->toBe(PayoutAttemptOperation::TRANSFER)
        ->and($attempts[0]->outcome)->toBe('timeout')
        ->and($attempts[1]->operation)->toBe(PayoutAttemptOperation::STATUS)
        ->and($attempts[1]->outcome)->toBe('succeeded');

    /** And the run closes, because its last uncertain item resolved (R33). */
    expect(PayoutRun::query()->firstOrFail()->status)->toBe(PayoutRunStatus::COMPLETED);
});

it('returns the balance when reconciliation discovers a real failure', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_uncertain_0002');

    /**
     * A timeout on a transfer that actually failed. Same uncertainty, opposite
     * truth — which is exactly why the timeout itself cannot be interpreted.
     */
    provider()->script([ScriptedMockProvider::OUTCOME_PERMANENT_FAILURE]);

    processItem($item->id);
    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::FAILED)
        ->and(InstructorBalance::query()->findOrFail($item->instructor_id)->available_minor)
        ->toBe($item->amount_minor);
});

it('keeps asking a provider that has not decided, on a widening ladder', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_uncertain_0003');

    /** Video scenario 5: accepted, confirmed two status checks later. */
    provider()->script([ScriptedMockProvider::OUTCOME_DELAYED_CONFIRMATION]);

    processItem($item->id);

    expect($item->refresh()->status)->toBe(PayoutItemStatus::UNKNOWN);

    /** First ask: still pending, rescheduled a minute out. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:02:00'));
    $this->artisan('payouts:reconcile', ['--sync' => true])->assertSuccessful();

    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::UNKNOWN)
        ->and($item->next_check_at?->toDateTimeString())->toBe('2026-09-15 09:03:00')
        /**
         * The run is honest about where it stands: everything was sent, and one
         * item is waiting on the provider. Not `completed`, which would claim
         * an outcome nobody has given (D-8, R33).
         */
        ->and(PayoutRun::query()->firstOrFail()->status)->toBe(PayoutRunStatus::COMPLETED_WITH_PENDING);

    /** Second ask: the provider confirms. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:04:00'));
    $this->artisan('payouts:reconcile', ['--sync' => true])->assertSuccessful();

    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($item->instructor_id)->paid_minor)
        ->toBe($item->amount_minor);
});
