<?php

declare(strict_types=1);

use App\Actions\Payouts\ProcessPayoutItemAction;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutItemStatus;
use App\Exceptions\ProviderUnavailableException;
use App\Jobs\ProcessPayoutItemJob;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutAttempt;
use App\Models\PayoutItem;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;

/*
 * Every answer a provider can give, and what it does to the money.
 *
 * The table in F07 is short and every row of it is a decision that costs real
 * money if it is wrong in either direction — returning a balance that was
 * actually paid, or recording a payment that never happened. These are the
 * rows.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('settles the money out of the platform when the provider succeeds', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_outcome_0001');
    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    expect(processItem($item->id))->toBe(PayoutItemStatus::SUCCEEDED);

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($item->settled_at)->not->toBeNull()
        ->and($item->next_check_at)->toBeNull()
        ->and($item->provider_reference)->not->toBeNull()
        ->and($balance->paid_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_CASH, 0))->toBeLessThan(300_000);
});

it('returns the balance to available when the provider definitively fails', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_outcome_0002');
    provider()->script([ScriptedMockProvider::OUTCOME_PERMANENT_FAILURE]);

    expect(processItem($item->id))->toBe(PayoutItemStatus::FAILED);

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    /**
     * The only path that gives money back, and it needs the provider to have
     * said so. The reversal is a new entry, not a deleted one: "we tried and it
     * failed" is part of the history (D-9).
     */
    expect($item->status)->toBe(PayoutItemStatus::FAILED)
        ->and($item->last_error)->toBe('account_closed')
        ->and($balance->available_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0)
        ->and($balance->paid_minor)->toBe(0)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_REVERSED)->count())->toBe(2)
        ->and(ledgerSumFor(LedgerAccountType::PROVIDER_IN_TRANSIT, $item->instructor_id))->toBe(0);

    /** And the next run picks that balance up again, because it is available. */
    $this->artisan('payouts:run', ['--run-key' => 'payout:after-failure'])->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(2);
});

it('records a timeout as unknown and leaves the money reserved', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_outcome_0003');
    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    expect(processItem($item->id))->toBe(PayoutItemStatus::UNKNOWN);

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($item->status)->toBe(PayoutItemStatus::UNKNOWN)
        /** Queued to be asked about again, soon — the money is already out. */
        ->and($item->next_check_at?->toDateTimeString())
        ->toBe(CarbonImmutable::now()->addMinute()->toDateTimeString())
        ->and($balance->reserved_minor)->toBe($item->amount_minor)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->paid_minor)->toBe(0)
        /** No ledger movement at all: nothing is known, so nothing is recorded. */
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(0)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_REVERSED)->count())->toBe(0);
});

it('parks a delayed confirmation as unknown rather than guessing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_outcome_0004');
    provider()->script([ScriptedMockProvider::OUTCOME_DELAYED_CONFIRMATION]);

    /** Accepted, not decided. Video scenario 5. */
    expect(processItem($item->id))->toBe(PayoutItemStatus::UNKNOWN)
        ->and(InstructorBalance::query()->findOrFail($item->instructor_id)->reserved_minor)
        ->toBe($item->amount_minor);

    /** The provider confirms after two status checks; F08 will be the one asking. */
    processItem($item->id);

    expect(processItem($item->id))->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1);
});

it('rethrows an unavailable provider so the queue retries, having sent nothing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_outcome_0005');
    provider()->script([ScriptedMockProvider::OUTCOME_UNAVAILABLE]);

    expect(fn () => processItem($item->id))->toThrow(ProviderUnavailableException::class);

    $item->refresh();

    /**
     * The request provably never left, so the item stays `submitted` with its
     * key and waits for the backoff ladder. Nothing was sent, so nothing is
     * unknown — and the money has not moved either way.
     */
    expect($item->status)->toBe(PayoutItemStatus::SUBMITTED)
        ->and($item->attempts)->toBe(1)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(0)
        ->and(InstructorBalance::query()->findOrFail($item->instructor_id)->reserved_minor)->toBe($item->amount_minor);

    /** And the retry, once the provider is back, sends with the same key. */
    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    expect(processItem($item->id))->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1);
});

it('parks an item for review when the job exhausts its retries', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_outcome_0006');
    provider()->script([ScriptedMockProvider::OUTCOME_UNAVAILABLE]);

    $job = new ProcessPayoutItemJob($item->id);

    try {
        $job->handle(app(ProcessPayoutItemAction::class));
    } catch (ProviderUnavailableException $expected) {
        $job->failed($expected);
    }

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    /**
     * `needs_review`, and the money stays reserved. Not back to `reserved`,
     * which would make it dispatchable again on no new information; not
     * `failed`, which would return a balance nobody has established was never
     * sent. A human decides, with the attempts in front of them.
     */
    expect($item->status)->toBe(PayoutItemStatus::NEEDS_REVIEW)
        ->and($item->last_error)->not->toBeNull()
        ->and($balance->reserved_minor)->toBe($item->amount_minor)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->paid_minor)->toBe(0);

    /** And a later delivery of the same job leaves it exactly there. */
    expect(processItem($item->id))->toBe(PayoutItemStatus::NEEDS_REVIEW)
        ->and(PayoutAttempt::query()->where('payout_item_id', $item->id)->count())->toBe(1);
});
