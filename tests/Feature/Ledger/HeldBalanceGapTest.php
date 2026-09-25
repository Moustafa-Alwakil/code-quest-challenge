<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Support\Ledger\BalanceDelta;
use Tests\Support\LedgerPostings;

/*
 * The one hole in F03's verification, pinned so F05 cannot walk into it.
 *
 * The hold is allocation state, not a ledger fact (R2), so
 * `InstructorBalanceService::recomputeFromLedger()` has nothing to recompute
 * `held` from and returns 0 for it — correct today, because
 * `earning_allocations` arrives with F05. The consequence is sharper than a
 * missing feature: **any snapshot with a non-zero `held_minor` is a red
 * `ledger:verify` run right now**, and `held` drags `available` with it.
 *
 * That makes three of the six BalanceDelta constructors unusable on their own
 * until F05 extends the recompute: `recognized()` without a matching
 * `released()`, `released()` without a prior `recognized()`, and
 * `clawedBack()` with anything in `fromHeldMinor`. F03's own tests work around
 * it with `LedgerPostings::recognizedAndReleased()`.
 *
 * These tests fail the day F05 makes `held` real. That is the point: they are
 * the alarm, and F05 should replace them with the real hold tests.
 *
 * assertLedgerBalanced() is not registered here — the subject is a red verifier.
 */

it('is red as soon as a recognition holds the money it earned', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        BalanceDelta::recognized($instructor->id, 7_000),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    /** The snapshot itself is right: 7 000 earned, all of it held, none available. */
    expect($balance->earned_minor)->toBe(7_000)
        ->and($balance->held_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->outstandingMinor())->toBe(7_000);

    /** The verifier disagrees, because it recomputes held as 0 and available as 7 000. */
    expect(ledgerIsClean())->toBeFalse();

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('held_minor')
        ->expectsOutputToContain('available_minor')
        ->assertExitCode(1);
})->note('F05 must teach recomputeFromLedger() to read earning_allocations in the same commit that first posts a real hold.');

it('is red when a clawback comes out of held rather than available', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
    );

    LedgerPostings::post(
        LedgerPostings::clawback(refundId: 1, instructorId: $instructor->id, amountMinor: 3_000),
        BalanceDelta::clawedBack($instructor->id, fromHeldMinor: 3_000, fromAvailableMinor: 0),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($balance->held_minor)->toBe(-3_000)
        ->and($balance->available_minor)->toBe(7_000)
        ->and(ledgerIsClean())->toBeFalse();
})->note('Free clawbacks against a live hold (D-6) cannot be verified until F05 supplies the allocation rows.');

it('is green only while every hold is released in the same posting', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
    );

    expect(InstructorBalance::query()->findOrFail($instructor->id)->held_minor)->toBe(0)
        ->and(ledgerIsClean())->toBeTrue();
});
