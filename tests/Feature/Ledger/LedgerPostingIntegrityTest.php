<?php

declare(strict_types=1);

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use Tests\Support\LedgerPostings;

/*
 * A partial duplicate — some legs already on the table, some not — is neither a
 * new posting nor a replay, and no correct caller can produce one. It means the
 * idempotency key is being used for two different things, so the only safe
 * answer is to throw and let the caller's transaction take the rest back out.
 *
 * The other half of the contract, that posting refuses to run outside a
 * transaction at all, is proven in tests/Unit/Services/LedgerPostingGuardTest:
 * RefreshDatabase holds a transaction open here, so the condition cannot arise.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('rejects a partial duplicate and rolls the whole posting back', function (): void {
    $instructor = Instructor::factory()->create();
    $other = Instructor::factory()->create();

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

    /**
     * The same entry type and reference as the posting above, so its first leg
     * collides on the unique key while its second is new.
     */
    $collision = LedgerTransaction::of(
        LedgerEntryType::PERIOD_RECOGNIZED,
        'accrual_period',
        1,
        LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $other->id, egp(10_000)),
    );

    expect(fn () => LedgerPostings::post(
        $collision,
        LedgerPostings::recognizedAndReleased($other->id, 10_000),
    ))->toThrow(LedgerIntegrityException::class, 'expected 2 legs, inserted 1');

    expect(LedgerEntry::query()->count())->toBe(3)
        ->and(LedgerEntry::query()->where('account_id', $other->id)->exists())->toBeFalse()
        ->and(InstructorBalance::query()->whereKey($other->id)->exists())->toBeFalse();
});

it('leaves the first instructor untouched when the second leg of a replay is new', function (): void {
    $instructor = Instructor::factory()->create();
    $other = Instructor::factory()->create();

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

    $before = InstructorBalance::query()->findOrFail($instructor->id);

    $collision = LedgerTransaction::of(
        LedgerEntryType::PERIOD_RECOGNIZED,
        'accrual_period',
        1,
        LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $other->id, egp(10_000)),
    );

    try {
        LedgerPostings::post($collision, LedgerPostings::recognizedAndReleased($other->id, 10_000));
    } catch (LedgerIntegrityException) {
        // Expected: asserted in the test above. Here the point is what survived it.
    }

    $after = InstructorBalance::query()->findOrFail($instructor->id);

    expect($after->earned_minor)->toBe($before->earned_minor)
        ->and($after->available_minor)->toBe($before->available_minor)
        ->and($after->last_ledger_entry_id)->toBe($before->last_ledger_entry_id);
});

it('does not apply the deltas of a replayed posting', function (): void {
    $instructor = Instructor::factory()->create();

    $reservation = fn () => LedgerPostings::reservation(
        payoutItemId: 5,
        instructorId: $instructor->id,
        amountMinor: 3_000,
    );

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

    LedgerPostings::post($reservation(), BalanceDelta::reserved($instructor->id, 3_000));
    $replayed = LedgerPostings::post($reservation(), BalanceDelta::reserved($instructor->id, 3_000));

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($replayed)->toBeFalse()
        ->and($balance->reserved_minor)->toBe(3_000)
        ->and($balance->available_minor)->toBe(4_000)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_RESERVED)->count())->toBe(2);
});
