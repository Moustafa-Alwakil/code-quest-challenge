<?php

declare(strict_types=1);

use App\Actions\Payouts\ProcessPayoutItemAction;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutAttemptOperation;
use App\Enums\PayoutItemStatus;
use App\Jobs\ProcessPayoutItemJob;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutAttempt;
use App\Models\PayoutItem;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

/*
 * **Required proof #2**: retried jobs never double-pay.
 *
 * Two halves, both here. 2a is the crude one — hand the same job to a worker
 * twice. 2b is the one that matters: the provider succeeds, the process dies
 * before recording it, and the retry has to discover what already happened
 * rather than doing it again.
 *
 * `transferCount()` is the provider's own count of times it moved money for a
 * key, and `callCount()` is how many times it was asked. The healthy shape is
 * calls > transfers: we retried, and the dedup absorbed it.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * One instructor with a reserved, undispatched payout item.
 *
 * `Bus::fake()` keeps the run from settling it, so each test below drives the
 * item itself and sees every intermediate state.
 */
function reservedItemFor(string $externalRef): PayoutItem
{
    Bus::fake();

    instructorWithAvailableBalance($externalRef);

    test()->artisan('payouts:run', ['--run-key' => 'payout:retry'])->assertSuccessful();

    return PayoutItem::query()->firstOrFail();
}

function processItem(int $payoutItemId): PayoutItemStatus
{
    return app(ProcessPayoutItemAction::class)($payoutItemId);
}

it('moves the money once when the same job is handled twice', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_retry_0001');

    provider()->script([ScriptedMockProvider::OUTCOME_SUCCESS]);

    $job = new ProcessPayoutItemJob($item->id);

    /** Two deliveries of the identical job, as an at-least-once queue produces. */
    $job->handle(app(ProcessPayoutItemAction::class));
    $job->handle(app(ProcessPayoutItemAction::class));

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1)
        /** One settlement posting, not two — the CAS refused the second. */
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(2)
        ->and($balance->paid_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0);

    /**
     * One provider interaction, because the second delivery never reached the
     * provider: the item was already terminal and the job returned before
     * calling anything. That is a stronger outcome than relying on the
     * provider's dedup to absorb a second send — the send never happened.
     */
    expect(PayoutAttempt::query()->where('payout_item_id', $item->id)->count())->toBe(1)
        ->and(provider()->callCount($item->idempotency_key))->toBe(1);
});

it('settles from the provider status when a crash lost the original answer', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_retry_0002');

    /**
     * The provider moves the money and then the answer is lost — a timeout
     * *after* success, which is the failure D-8 exists for. The worker records
     * `unknown`, not `failed`: the balance must not come back.
     */
    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    expect(processItem($item->id))->toBe(PayoutItemStatus::UNKNOWN);

    $item->refresh();
    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($item->status)->toBe(PayoutItemStatus::UNKNOWN)
        ->and($item->next_check_at)->not->toBeNull()
        /** The money is still in transit: not paid, and not returned. */
        ->and($balance->reserved_minor)->toBe($item->amount_minor)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->paid_minor)->toBe(0)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(0);

    /** The retry asks first, and the answer settles it without a second transfer. */
    expect(processItem($item->id))->toBe(PayoutItemStatus::SUCCEEDED);

    $item->refresh();
    $balance->refresh();

    expect($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1)
        ->and($balance->paid_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0);

    /** A status call, not a transfer, is what resolved it. */
    $operations = PayoutAttempt::query()
        ->where('payout_item_id', $item->id)
        ->orderBy('attempt_no')
        ->pluck('operation');

    expect($operations->first())->toBe(PayoutAttemptOperation::TRANSFER)
        ->and($operations->last())->toBe(PayoutAttemptOperation::STATUS);
});

it('asks before it sends, so a weak provider dedup cannot be exploited', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $item = reservedItemFor('ch_retry_0003');

    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    processItem($item->id);
    processItem($item->id);

    /**
     * Exactly one transfer call reached the provider on the retry path: the
     * second pass asked `getStatus` and stopped there. Our mock would have
     * deduped a second send anyway — the point is not to rely on that.
     */
    expect(provider()->callCount($item->idempotency_key))->toBe(1)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1);
});
