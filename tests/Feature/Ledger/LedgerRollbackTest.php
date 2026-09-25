<?php

declare(strict_types=1);

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerPostings;

/*
 * Rule 2 of posting: every posting commits with the business state change it
 * records. The half of that rule the existing tests do not reach is what
 * happens to *already applied* snapshot increments when a later step in the
 * same transaction fails.
 *
 * LedgerPostingIntegrityTest proves the failing posting leaves nothing behind.
 * These prove the transaction around it takes the earlier, successful work out
 * too — which is the difference between "the ledger is consistent" and "the
 * ledger is consistent with everything else".
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('rolls a successful posting back out of the snapshot when a later posting fails', function (): void {
    $instructor = Instructor::factory()->create();
    $other = Instructor::factory()->create();

    /** A first, committed posting, so the snapshot row exists with real money in it. */
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

    $collision = LedgerTransaction::of(
        LedgerEntryType::PERIOD_RECOGNIZED,
        'accrual_period',
        1,
        LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(10_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $other->id, egp(10_000)),
    );

    /**
     * One transaction, two postings: the second is a partial duplicate. The
     * first one's snapshot increment is already in the row when it throws.
     */
    $attempt = fn () => DB::transaction(function () use ($instructor, $other, $collision): void {
        app(LedgerService::class)->post(
            LedgerPostings::recognition(
                periodId: 2,
                subscriptionId: 1,
                instructorId: $instructor->id,
                grossMinor: 10_000,
                instructorMinor: 7_000,
            ),
            LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
        );

        /** Proof the increment landed before the failure — it is this that must be undone. */
        expect(InstructorBalance::query()->findOrFail($instructor->id)->earned_minor)->toBe(14_000);

        app(LedgerService::class)->post($collision, LedgerPostings::recognizedAndReleased($other->id, 10_000));
    });

    expect($attempt)->toThrow(LedgerIntegrityException::class);

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    /** Back to the first posting alone: the period-2 legs and their increment are both gone. */
    expect($balance->earned_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(7_000)
        ->and(LedgerEntry::query()->count())->toBe(3)
        ->and(LedgerEntry::query()->where('reference_id', 2)->exists())->toBeFalse()
        ->and(InstructorBalance::query()->whereKey($other->id)->exists())->toBeFalse();
});

it('rolls the snapshot back when the business state change fails after a good posting', function (): void {
    $instructor = Instructor::factory()->create();

    $attempt = fn () => DB::transaction(function () use ($instructor): void {
        app(LedgerService::class)->post(
            LedgerPostings::recognition(
                periodId: 1,
                subscriptionId: 1,
                instructorId: $instructor->id,
                grossMinor: 10_000,
                instructorMinor: 7_000,
            ),
            LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
        );

        /** The posting succeeded; the status change it belongs to does not. */
        throw new RuntimeException('the accrual period could not be marked recognized');
    });

    expect($attempt)->toThrow(RuntimeException::class);

    /**
     * Nothing survives: no legs, and no snapshot row created by the upsert.
     * A ledger entry without its business row is money the system cannot
     * explain, which is why posting is never allowed its own transaction.
     */
    expect(LedgerEntry::query()->count())->toBe(0)
        ->and(InstructorBalance::query()->count())->toBe(0);
});

it('rolls a replay back cleanly, leaving the original posting intact', function (): void {
    $instructor = Instructor::factory()->create();

    $recognition = fn () => LedgerPostings::recognition(
        periodId: 1,
        subscriptionId: 1,
        instructorId: $instructor->id,
        grossMinor: 10_000,
        instructorMinor: 7_000,
    );

    LedgerPostings::post($recognition(), LedgerPostings::recognizedAndReleased($instructor->id, 7_000));

    $attempt = fn () => DB::transaction(function () use ($recognition, $instructor): void {
        $replayed = app(LedgerService::class)->post(
            $recognition(),
            LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
        );

        expect($replayed)->toBeFalse();

        throw new RuntimeException('the caller decided to abort anyway');
    });

    expect($attempt)->toThrow(RuntimeException::class);

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect(LedgerEntry::query()->count())->toBe(3)
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(7_000);
});

it('applies no delta at all when a posting is rejected before it writes', function (): void {
    $instructor = Instructor::factory()->create();

    /** Two legs on the same account: rejected by the value object, so nothing reaches the table. */
    expect(fn () => LedgerTransaction::of(
        LedgerEntryType::PERIOD_RECOGNIZED,
        'accrual_period',
        1,
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructor->id, egp(3_000)),
        LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructor->id, egp(4_000)),
        LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, 1, egp(7_000)),
    ))->toThrow(App\Exceptions\InvalidLedgerTransactionException::class);

    expect(LedgerEntry::query()->count())->toBe(0)
        ->and(InstructorBalance::query()->count())->toBe(0);
});

it('never applies a delta for a posting whose legs were all duplicates', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::reservation(payoutItemId: 9, instructorId: $instructor->id, amountMinor: 2_500),
        BalanceDelta::reserved($instructor->id, 2_500),
    );

    /** A replay carrying a *different*, wrong delta still applies nothing. */
    $replayed = LedgerPostings::post(
        LedgerPostings::reservation(payoutItemId: 9, instructorId: $instructor->id, amountMinor: 2_500),
        BalanceDelta::reserved($instructor->id, 999_999),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($replayed)->toBeFalse()
        ->and($balance->reserved_minor)->toBe(2_500)
        ->and($balance->available_minor)->toBe(-2_500);
});
