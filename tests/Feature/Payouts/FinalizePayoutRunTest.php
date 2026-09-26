<?php

declare(strict_types=1);

use App\Actions\Payouts\FinalizePayoutRunAction;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Models\PayoutItem;
use App\Models\PayoutRun;

/*
 * What a run's status says about its items, and what it refuses to say.
 *
 * The distinction that matters is D-8's: a run holding an `unknown` item has
 * not failed and has not finished. `completed_with_pending` is the honest
 * answer, and F08 re-evaluates the run as reconciliation resolves those items —
 * which is why finalization is a compare-and-swap that can be run again rather
 * than a one-shot write.
 *
 * Items here are factory-made and carry no ledger entries, so
 * `assertLedgerBalanced()` is not registered: the subject is the status
 * machine, and the money it describes is proved in the reservation tests.
 */

function finalize(PayoutRun $run): PayoutRunStatus
{
    return app(FinalizePayoutRunAction::class)($run->id);
}

it('calls a run with nothing left to send completed', function (): void {
    $run = PayoutRun::factory()->dispatched()->create();

    PayoutItem::factory()->for($run)->succeeded()->create();
    PayoutItem::factory()->for($run)->failed()->create();

    expect(finalize($run))->toBe(PayoutRunStatus::COMPLETED)
        ->and($run->refresh()->status)->toBe(PayoutRunStatus::COMPLETED)
        ->and($run->finished_at)->not->toBeNull();
});

it('will not call a run complete while the provider still owes an answer', function (PayoutItemStatus $pending): void {
    $run = PayoutRun::factory()->dispatched()->create();

    PayoutItem::factory()->for($run)->succeeded()->create();
    PayoutItem::factory()->for($run)->create(['status' => $pending]);

    expect(finalize($run))->toBe(PayoutRunStatus::COMPLETED_WITH_PENDING)
        ->and($run->refresh()->status)->toBe(PayoutRunStatus::COMPLETED_WITH_PENDING);
})->with([
    'submitted' => PayoutItemStatus::SUBMITTED,
    'unknown' => PayoutItemStatus::UNKNOWN,
    'needs review' => PayoutItemStatus::NEEDS_REVIEW,
]);

it('leaves a run dispatched while items are still waiting for a worker', function (): void {
    $run = PayoutRun::factory()->create();

    PayoutItem::factory()->for($run)->create();
    PayoutItem::factory()->for($run)->succeeded()->create();

    /**
     * `reserved` means nobody has tried to send it. Calling that
     * `completed_with_pending` would say "we sent everything and are waiting to
     * hear", which is a different and untrue statement.
     */
    expect(finalize($run))->toBe(PayoutRunStatus::DISPATCHED)
        ->and($run->refresh()->status)->toBe(PayoutRunStatus::DISPATCHED)
        ->and($run->finished_at)->toBeNull();
});

it('completes a run that reserved nothing at all', function (): void {
    $run = PayoutRun::factory()->create();

    expect(finalize($run))->toBe(PayoutRunStatus::COMPLETED)
        ->and($run->refresh()->status)->toBe(PayoutRunStatus::COMPLETED);
});

it('can be run again as reconciliation resolves the last unknown item', function (): void {
    $run = PayoutRun::factory()->dispatched()->create();

    $unknown = PayoutItem::factory()->for($run)->unknown()->create();
    PayoutItem::factory()->for($run)->succeeded()->create();

    expect(finalize($run))->toBe(PayoutRunStatus::COMPLETED_WITH_PENDING);

    /** What F08 does once the provider finally answers. */
    $unknown->update(['status' => PayoutItemStatus::SUCCEEDED]);

    expect(finalize($run))->toBe(PayoutRunStatus::COMPLETED)
        ->and($run->refresh()->status)->toBe(PayoutRunStatus::COMPLETED);

    /** And again is a no-op rather than a second transition. */
    expect(finalize($run))->toBe(PayoutRunStatus::COMPLETED);
});
