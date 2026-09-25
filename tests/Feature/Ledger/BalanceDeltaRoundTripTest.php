<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Support\Ledger\BalanceDelta;
use Tests\Support\LedgerPostings;

/*
 * BalanceDeltaTest pins the *shape* of each named constructor in isolation.
 * That is not enough: a delta can have exactly the intended shape and still
 * disagree with what the ledger recomputes from the posting it accompanies,
 * and it is the disagreement — not the shape — that corrupts F05-F09.
 *
 * So every test here posts the real transaction, applies the matching delta and
 * lets `ledger:verify` recompute from the ledger. A wrong sign anywhere in the
 * vocabulary is a red run, not a green one.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('keeps recognized money out of available until the hold is released', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        BalanceDelta::recognized($instructor->id, 7_000)
            ->plus(BalanceDelta::released($instructor->id, 7_000)),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    /** recognized(+earned,+held) then released(-held,+available) nets to earned and available. */
    expect($balance->earned_minor)->toBe(7_000)
        ->and($balance->held_minor)->toBe(0)
        ->and($balance->available_minor)->toBe(7_000)
        ->and($balance->outstandingMinor())->toBe(7_000);
});

it('returns a reversed reservation to available without counting it as paid', function (): void {
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
        LedgerPostings::reservation(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 7_000),
        BalanceDelta::reserved($instructor->id, 7_000),
    );

    LedgerPostings::post(
        LedgerPostings::reversal(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 7_000),
        BalanceDelta::reversed($instructor->id, 7_000),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    /** A failed transfer is not a payment: the money is available again and paid is still 0. */
    expect($balance->reserved_minor)->toBe(0)
        ->and($balance->available_minor)->toBe(7_000)
        ->and($balance->paid_minor)->toBe(0)
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->outstandingMinor())->toBe(7_000);
});

it('does not let a reversal be mistaken for new earnings', function (): void {
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
        LedgerPostings::reservation(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 7_000),
        BalanceDelta::reserved($instructor->id, 7_000),
    );

    LedgerPostings::post(
        LedgerPostings::reversal(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 7_000),
        BalanceDelta::reversed($instructor->id, 7_000),
    );

    /**
     * The reversal credits instructor_payable, exactly as a recognition does.
     * Only the entry type tells them apart — if the recompute keyed `earned` off
     * the account instead, this would read 14 000.
     */
    expect(InstructorBalance::query()->findOrFail($instructor->id)->earned_minor)->toBe(7_000);
});

it('moves a payment through cash and deferred revenue without touching any balance', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(LedgerPostings::payment(paymentId: 1, subscriptionId: 1, amountMinor: 120_000));

    expect(InstructorBalance::query()->count())->toBe(0);

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

    expect(InstructorBalance::query()->findOrFail($instructor->id)->earned_minor)->toBe(7_000);
});

it('carries the whole lifecycle of one instructor without losing a piastre', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(LedgerPostings::payment(paymentId: 1, subscriptionId: 1, amountMinor: 30_000));

    foreach ([1, 2, 3] as $period) {
        LedgerPostings::post(
            LedgerPostings::recognition(
                periodId: $period,
                subscriptionId: 1,
                instructorId: $instructor->id,
                grossMinor: 10_000,
                instructorMinor: 7_000,
            ),
            LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
        );
    }

    LedgerPostings::post(
        LedgerPostings::reservation(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 21_000),
        BalanceDelta::reserved($instructor->id, 21_000),
    );

    LedgerPostings::post(
        LedgerPostings::reversal(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 21_000),
        BalanceDelta::reversed($instructor->id, 21_000),
    );

    LedgerPostings::post(
        LedgerPostings::reservation(payoutItemId: 2, instructorId: $instructor->id, amountMinor: 21_000),
        BalanceDelta::reserved($instructor->id, 21_000),
    );

    LedgerPostings::post(
        LedgerPostings::settlement(payoutItemId: 2, instructorId: $instructor->id, amountMinor: 21_000),
        BalanceDelta::settled($instructor->id, 21_000),
    );

    LedgerPostings::post(
        LedgerPostings::clawback(refundId: 1, instructorId: $instructor->id, amountMinor: 7_000),
        BalanceDelta::clawedBack($instructor->id, fromHeldMinor: 0, fromAvailableMinor: 7_000),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    /**
     * Earned 21 000, all of it paid out, then one period refunded in full:
     * the instructor now owes the platform 7 000, carried forward (D-7).
     */
    expect($balance->earned_minor)->toBe(21_000)
        ->and($balance->paid_minor)->toBe(21_000)
        ->and($balance->clawed_back_minor)->toBe(7_000)
        ->and($balance->reserved_minor)->toBe(0)
        ->and($balance->held_minor)->toBe(0)
        ->and($balance->available_minor)->toBe(-7_000)
        ->and($balance->outstandingMinor())->toBe(-7_000)
        ->and($balance->outstandingMinor())
        ->toBe($balance->available_minor + $balance->held_minor + $balance->reserved_minor);
});
