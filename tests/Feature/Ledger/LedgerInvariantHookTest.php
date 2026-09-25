<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\LedgerPostings;

/*
 * `assertLedgerBalanced()` is registered in afterEach across the money-touching
 * files, so every one of those tests leans on it for invariants I1-I4. A helper
 * that silently no-ops would make all of them pass for the wrong reason — and
 * nothing else in the suite would notice.
 *
 * So the helper gets its own tests: one per invariant it claims to cover,
 * each corrupting the ledger and asserting the helper *fails*.
 */

function seedForHook(): Instructor
{
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

    return $instructor;
}

it('passes on a ledger that is actually balanced', function (): void {
    seedForHook();

    assertLedgerBalanced();
});

it('fails when the ledger no longer sums to zero (I1)', function (): void {
    seedForHook();

    LedgerEntry::factory()->create(['amount_minor' => 1, 'reference_id' => 777]);

    expect(fn () => assertLedgerBalanced())
        ->toThrow(AssertionFailedError::class, 'ledger sum');
});

it('fails when one transaction does not sum to zero (I2)', function (): void {
    seedForHook();

    LedgerEntry::factory()->create([
        'transaction_uuid' => '33333333-3333-4333-8333-333333333333',
        'amount_minor' => 250,
        'reference_id' => 778,
    ]);

    LedgerEntry::factory()->create([
        'transaction_uuid' => '44444444-4444-4444-8444-444444444444',
        'amount_minor' => -250,
        'reference_id' => 779,
    ]);

    expect(fn () => assertLedgerBalanced())
        ->toThrow(AssertionFailedError::class, 'transaction sum');
});

it('fails when a snapshot field drifts from the ledger by one piastre (I3)', function (): void {
    $instructor = seedForHook();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['earned_minor' => 7_001]);

    expect(fn () => assertLedgerBalanced())
        ->toThrow(AssertionFailedError::class, 'earned_minor');
});

it('fails when the outstanding identity breaks (I4)', function (): void {
    $instructor = seedForHook();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['reserved_minor' => 1]);

    expect(fn () => assertLedgerBalanced())
        ->toThrow(AssertionFailedError::class, 'outstanding = available + held + reserved');
});

it('names the instructor and the exact numbers, not just "a mismatch"', function (): void {
    $instructor = seedForHook();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['available_minor' => 6_999]);

    expect(fn () => assertLedgerBalanced())
        ->toThrow(AssertionFailedError::class, "instructor {$instructor->id}")
        ->and(fn () => assertLedgerBalanced())
        ->toThrow(AssertionFailedError::class, 'expected 7000, got 6999');
});
