<?php

declare(strict_types=1);

use App\Actions\Ledger\VerifyLedgerAction;
use App\DTOs\Ledger\VerifyLedgerData;
use App\Enums\LedgerAccountType;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerMismatch;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerPostings;

/*
 * A check that cannot go red is not a check.
 *
 * VerifyLedgerCommandTest proves the command's exit code and output. This file
 * drives each of the four checks to failure *on its own*, so none of them is
 * passing because a different check happened to catch the same corruption —
 * and every snapshot column is corrupted in turn, because a comparison loop
 * that skips a column would still look green on the other five.
 *
 * assertLedgerBalanced() is deliberately not registered here: corrupting the
 * ledger is the subject.
 */

/**
 * @return list<string>
 */
function checksThatFailed(?int $instructorId = null): array
{
    $result = app(VerifyLedgerAction::class)(VerifyLedgerData::fromCommand(
        $instructorId === null ? null : (string) $instructorId,
        failFast: false,
    ));

    return array_values(array_unique(array_map(
        static fn (array $row): string => $row['check'],
        $result->toRows(),
    )));
}

function seedOneInstructor(): Instructor
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

    LedgerPostings::post(
        LedgerPostings::reservation(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 3_000),
        BalanceDelta::reserved($instructor->id, 3_000),
    );

    LedgerPostings::post(
        LedgerPostings::settlement(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 3_000),
        BalanceDelta::settled($instructor->id, 3_000),
    );

    LedgerPostings::post(
        LedgerPostings::clawback(refundId: 1, instructorId: $instructor->id, amountMinor: 1_000),
        BalanceDelta::clawedBack($instructor->id, fromHeldMinor: 0, fromAvailableMinor: 1_000),
    );

    return $instructor;
}

it('is green over a full earn, reserve, settle and claw-back lifecycle', function (): void {
    $instructor = seedOneInstructor();

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    /** 7 000 earned, 3 000 paid, 1 000 clawed back: 3 000 left, all of it available. */
    expect(checksThatFailed())->toBe([])
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->paid_minor)->toBe(3_000)
        ->and($balance->clawed_back_minor)->toBe(1_000)
        ->and($balance->reserved_minor)->toBe(0)
        ->and($balance->available_minor)->toBe(3_000)
        ->and($balance->outstandingMinor())->toBe(3_000);
});

it('fails check 1 alone when the ledger as a whole is out by a piastre', function (): void {
    $instructor = seedOneInstructor();

    /**
     * One extra leg on a platform account, with its own transaction_uuid: the
     * table no longer sums to zero and that one transaction does not either,
     * but no instructor balance is touched.
     */
    LedgerEntry::factory()->create(['amount_minor' => 1, 'reference_id' => 999]);

    expect(checksThatFailed())
        ->toContain(LedgerMismatch::CHECK_LEDGER_SUM)
        ->and(checksThatFailed())->not->toContain(LedgerMismatch::CHECK_SNAPSHOT);

    /** The instructor's own numbers are still right — the damage is ledger-wide. */
    expect(checksThatFailed($instructor->id))->toBe([]);
});

it('fails check 2 alone when two transactions are individually unbalanced but cancel out', function (): void {
    seedOneInstructor();

    LedgerEntry::factory()->create([
        'transaction_uuid' => '11111111-1111-4111-8111-111111111111',
        'amount_minor' => 500,
        'reference_id' => 901,
    ]);

    LedgerEntry::factory()->create([
        'transaction_uuid' => '22222222-2222-4222-8222-222222222222',
        'amount_minor' => -500,
        'reference_id' => 902,
    ]);

    /**
     * The table still sums to zero, so check 1 is happy. Only the per-transaction
     * grouping catches this — which is the entire reason check 2 exists.
     */
    expect(app(VerifyLedgerAction::class)(VerifyLedgerData::everything())->mismatchCount())->toBe(2)
        ->and(checksThatFailed())->toBe([LedgerMismatch::CHECK_TRANSACTION_SUM]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('transaction sum')
        ->doesntExpectOutputToContain('ledger sum')
        ->assertExitCode(1);
});

it('fails check 3 whichever snapshot column is corrupted', function (string $column, int $wrongValue): void {
    $instructor = seedOneInstructor();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update([$column => $wrongValue]);

    expect(checksThatFailed())->toContain(LedgerMismatch::CHECK_SNAPSHOT);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain($column)
        ->assertExitCode(1);
})->with([
    'earned_minor' => ['earned_minor', 7_001],
    'clawed_back_minor' => ['clawed_back_minor', 999],
    'held_minor' => ['held_minor', 1],
    'available_minor' => ['available_minor', 2_999],
    'reserved_minor' => ['reserved_minor', 5],
    'paid_minor' => ['paid_minor', 3_001],
]);

it('catches a single drifted piastre in either direction', function (int $drift): void {
    $instructor = seedOneInstructor();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['available_minor' => 3_000 + $drift]);

    expect(checksThatFailed())->toContain(LedgerMismatch::CHECK_SNAPSHOT);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain((string) (3_000 + $drift))
        ->assertExitCode(1);
})->with([1, -1]);

it('fails check 4 alone when the snapshot is self-consistent with the ledger but not with itself', function (): void {
    $instructor = seedOneInstructor();

    /**
     * Break the identity without breaking any single field's recomputation:
     * move a piastre from earned into paid. Both columns now disagree with the
     * ledger too, so to isolate check 4 we compare against the ledger by hand.
     */
    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['held_minor' => 250, 'available_minor' => 2_750]);

    $failures = checksThatFailed($instructor->id);

    /** held and available both drifted from the ledger, and the identity still holds. */
    expect($failures)->toContain(LedgerMismatch::CHECK_SNAPSHOT)
        ->and($failures)->not->toContain(LedgerMismatch::CHECK_IDENTITY);

    /** Now break only the identity arithmetic. */
    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['held_minor' => 0, 'available_minor' => 3_000, 'reserved_minor' => 7]);

    expect(checksThatFailed($instructor->id))->toContain(LedgerMismatch::CHECK_IDENTITY);
});

it('does not silently pass when the whole snapshot table is missing', function (): void {
    $instructor = seedOneInstructor();

    InstructorBalance::query()->whereKey($instructor->id)->delete();

    expect(checksThatFailed())->toBe([LedgerMismatch::CHECK_MISSING_SNAPSHOT]);
});

it('reports zero instructors checked rather than claiming success over nothing', function (): void {
    $result = app(VerifyLedgerAction::class)(VerifyLedgerData::everything());

    expect($result->isClean())->toBeTrue()
        ->and($result->entriesChecked)->toBe(0)
        ->and($result->instructorsChecked)->toBe(0);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('0 entries, 0 transactions, 0 instructor balances')
        ->assertExitCode(0);
});

it('refuses to call a run over nothing a success: exit 2 for an instructor id that does not exist', function (): void {
    seedOneInstructor();

    $this->artisan('ledger:verify', ['--instructor' => '999999'])
        ->expectsOutputToContain('checked nothing: instructor 999999 has no balance snapshot row')
        ->assertExitCode(2);
})->note('R21: exit 2 is "useless invocation". Exit 1 stays reserved for "the money is wrong", so a scheduler alert on 1 never needs interpreting.');

it('still reaches exit 1 for a real mismatch when --instructor is given', function (): void {
    $instructor = seedOneInstructor();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['paid_minor' => 99]);

    $this->artisan('ledger:verify', ['--instructor' => (string) $instructor->id])
        ->expectsOutputToContain('paid_minor')
        ->assertExitCode(1);
});

it('exits 0 for a healthy instructor, so exit 2 cannot be reached by a sound invocation', function (): void {
    $instructor = seedOneInstructor();

    $this->artisan('ledger:verify', ['--instructor' => (string) $instructor->id])
        ->expectsOutputToContain('ledger:verify passed')
        ->assertExitCode(0);
});

/*
 * `last_ledger_entry_id` is the one snapshot column `ledger:verify` does *not*
 * recompute (R19). This test pins the gap rather than the guarantee: if a later
 * feature starts trusting that column, it is trusting an unverified value.
 */

it('does not verify last_ledger_entry_id: the watermark can be wrong and the run stays green', function (): void {
    $instructor = seedOneInstructor();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['last_ledger_entry_id' => 0]);

    expect(checksThatFailed())->toBe([]);

    $this->artisan('ledger:verify')->assertExitCode(0);
})->note('Assumption 3: the watermark is a debugging aid, not an invariant. Nothing may branch on it.');

/*
 * Currency is a snapshot field like any other, so check 3 recomputes it (R21).
 */

it('catches a snapshot whose currency disagrees with its ledger entries', function (): void {
    $instructor = seedOneInstructor();

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['currency' => 'USD']);

    expect(checksThatFailed())->toBe([LedgerMismatch::CHECK_CURRENCY]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('EGP')
        ->assertExitCode(1);
});

it('names both currencies rather than picking one when an instructor has entries in two', function (): void {
    $instructor = seedOneInstructor();

    /** One leg forced into a second currency: the ledger now contradicts itself. */
    DB::table('ledger_entries')
        ->where('account_type', LedgerAccountType::INSTRUCTOR_PAYABLE->value)
        ->where('account_id', $instructor->id)
        ->limit(1)
        ->update(['currency' => 'USD']);

    $rows = app(VerifyLedgerAction::class)(VerifyLedgerData::everything())->toRows();
    $currencyRow = collect($rows)->firstWhere('check', LedgerMismatch::CHECK_CURRENCY);

    expect($currencyRow)->not->toBeNull()
        ->and($currencyRow['expected'])->toBe('EGP + USD')
        ->and($currencyRow['actual'])->toBe('EGP')
        ->and($currencyRow['delta'])->toBe('-');
});

it('leaves an instructor with no ledger entries alone, whatever currency their row carries', function (): void {
    InstructorBalance::factory()->create(['currency' => 'USD']);

    expect(checksThatFailed())->toBe([]);

    $this->artisan('ledger:verify')->assertExitCode(0);
})->note('R21 skips instructors with no entries: an all-zero row carries a currency the ledger has nothing to say about yet.');
