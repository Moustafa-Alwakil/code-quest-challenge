<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Enums\PayoutAttemptOperation;
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
 * The way out of `needs_review` — the one state the system refuses to decide
 * for itself.
 *
 * Money parked there is frozen in `provider_in_transit`: not paid, not
 * returned, and untouchable by any automated path, because both directions are
 * wrong without evidence. These tests cover the evidence arriving: a person
 * looked it up and told the system, through the same settle and reverse actions
 * every other outcome uses.
 *
 * What is really being checked is that the manual path is a manual *answer*,
 * not a manual route into the ledger.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * An item that genuinely reached `needs_review`: the provider was unreachable
 * and the job exhausted its attempts.
 *
 * Built through the real failure path rather than by setting a status, so the
 * money really is frozen where the tests below expect to find it.
 */
function itemUnderReview(string $externalRef): PayoutItem
{
    $item = reservedItemFor($externalRef);

    provider()->script([ScriptedMockProvider::OUTCOME_UNAVAILABLE]);

    $job = new ProcessPayoutItemJob($item->id);

    try {
        $job->handle(app(App\Actions\Payouts\ProcessPayoutItemAction::class));
    } catch (ProviderUnavailableException $expected) {
        $job->failed($expected);
    }

    return $item->refresh();
}

it('settles an item a person established had succeeded', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemUnderReview('ch_resolve_0001');

    expect($item->status)->toBe(PayoutItemStatus::NEEDS_REVIEW);

    $balanceBefore = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($balanceBefore->reserved_minor)->toBe($item->amount_minor)
        ->and($balanceBefore->paid_minor)->toBe(0);

    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => 'succeeded',
        '--reason' => 'Confirmed on the provider dashboard, ref TRX-99',
        '--provider-ref' => 'TRX-99',
    ])
        ->expectsOutputToContain('recorded as succeeded')
        ->assertSuccessful();

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and($item->provider_reference)->toBe('TRX-99')
        ->and($item->settled_at)->not->toBeNull()
        /** The money left the platform through the ordinary settlement posting. */
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(2)
        ->and($balance->paid_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0);
});

it('returns the balance when a person established it had failed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemUnderReview('ch_resolve_0002');

    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => 'failed',
        '--reason' => 'Bank confirmed the transfer never left',
    ])
        ->expectsOutputToContain('recorded as failed')
        ->assertSuccessful();

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($item->status)->toBe(PayoutItemStatus::FAILED)
        ->and($item->last_error)->toBe('Bank confirmed the transfer never left')
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_REVERSED)->count())->toBe(2)
        ->and($balance->available_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0)
        ->and($balance->paid_minor)->toBe(0);
});

it('records the operator decision beside the provider own answers', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemUnderReview('ch_resolve_0003');

    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => 'succeeded',
        '--reason' => 'Support ticket 4821 confirms receipt',
    ])->assertSuccessful();

    $attempts = PayoutAttempt::query()->where('payout_item_id', $item->id)->orderBy('attempt_no')->get();

    /**
     * The provider's failed call, then the person's finding — one list, in
     * order. That sequence is the whole justification for letting a human move
     * money here.
     */
    expect($attempts->last()->operation)->toBe(PayoutAttemptOperation::MANUAL)
        ->and($attempts->last()->response)->toBe('Support ticket 4821 confirms receipt')
        ->and($attempts->last()->outcome)->toBe('succeeded')
        ->and($attempts->count())->toBeGreaterThan(1);
});

it('refuses an item that is not waiting on a human', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_resolve_0004');

    /** Still `reserved`: a worker has not even tried it yet. */
    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => 'succeeded',
        '--reason' => 'I think it worked',
    ])
        ->expectsOutputToContain('is reserved, not needs_review')
        ->assertExitCode(2);

    expect($item->refresh()->status)->toBe(PayoutItemStatus::RESERVED)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(0);
});

it('refuses to move money without a reason', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemUnderReview('ch_resolve_0005');

    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => 'succeeded',
    ])
        ->expectsOutputToContain('--reason is required')
        ->assertExitCode(2);

    expect($item->refresh()->status)->toBe(PayoutItemStatus::NEEDS_REVIEW);
});

it('refuses an outcome a person cannot establish', function (string $as): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemUnderReview('ch_resolve_'.md5($as));

    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => $as,
        '--reason' => 'because',
    ])
        ->expectsOutputToContain("--as must be 'succeeded' or 'failed'")
        ->assertExitCode(2);

    expect($item->refresh()->status)->toBe(PayoutItemStatus::NEEDS_REVIEW);
})->with(['unknown', 'needs_review', 'reserved', '']);

it('refuses a second resolve of the same item', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = itemUnderReview('ch_resolve_0006');

    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => 'succeeded',
        '--reason' => 'Confirmed by the provider',
    ])->assertSuccessful();

    $settledAt = $item->refresh()->settled_at;
    $attempts = PayoutAttempt::query()->where('payout_item_id', $item->id)->count();

    /**
     * The obvious operator mistake: running it twice, or two people resolving
     * the same ticket. The guard refuses on status rather than moving money a
     * second time, and refuses *before* writing an attempt row — so a rejected
     * command leaves no trace suggesting it did something.
     */
    $this->artisan('payouts:resolve', [
        'item' => (string) $item->id,
        '--as' => 'failed',
        '--reason' => 'Changed my mind',
    ])
        ->expectsOutputToContain('is succeeded, not needs_review')
        ->assertExitCode(2);

    $item->refresh();

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and($item->settled_at?->toDateTimeString())->toBe($settledAt?->toDateTimeString())
        ->and(PayoutAttempt::query()->where('payout_item_id', $item->id)->count())->toBe($attempts)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_REVERSED)->count())->toBe(0);
});

it('refuses an item that does not exist', function (): void {
    $this->artisan('payouts:resolve', [
        'item' => '99999',
        '--as' => 'succeeded',
        '--reason' => 'nope',
    ])
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(2);
});
