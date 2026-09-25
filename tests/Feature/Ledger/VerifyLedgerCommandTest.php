<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Support\Ledger\BalanceDelta;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerPostings;

/*
 * The snapshot is a cache. This is how we know it is telling the truth.
 *
 * `assertLedgerBalanced()` is deliberately not registered in this file: these
 * tests corrupt the ledger on purpose, and the hook exists to catch exactly
 * that. Every green expectation below is the same assertion made explicitly.
 */

/**
 * A recognized period, then a reservation — enough that all four checks have
 * real numbers to work with rather than zeroes.
 */
function seedLedger(Instructor $instructor, int $grossMinor = 10_000, int $instructorMinor = 7_000, int $reserveMinor = 3_000): void
{
    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: $instructor->id,
            subscriptionId: $instructor->id,
            instructorId: $instructor->id,
            grossMinor: $grossMinor,
            instructorMinor: $instructorMinor,
        ),
        LedgerPostings::recognizedAndReleased($instructor->id, $instructorMinor),
    );

    LedgerPostings::post(
        LedgerPostings::reservation(
            payoutItemId: $instructor->id,
            instructorId: $instructor->id,
            amountMinor: $reserveMinor,
        ),
        BalanceDelta::reserved($instructor->id, $reserveMinor),
    );
}

it('passes on an empty ledger', function (): void {
    $this->artisan('ledger:verify')
        ->expectsOutputToContain('ledger:verify passed')
        ->assertExitCode(0);
});

it('passes on posted data and says how much it checked', function (): void {
    $instructor = Instructor::factory()->create();
    seedLedger($instructor);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('5 entries, 2 transactions, 1 instructor balances')
        ->assertExitCode(0);
});

it('fails with exit 1 and names the field when a snapshot row is corrupted', function (): void {
    $instructor = Instructor::factory()->create();
    seedLedger($instructor);

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['earned_minor' => 1]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('earned_minor')
        ->expectsOutputToContain('ledger:verify FAILED')
        ->assertExitCode(1);
});

it('reports the exact size of the drift', function (): void {
    $instructor = Instructor::factory()->create();
    seedLedger($instructor);

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['available_minor' => 4_001]);

    /** One drifted piastre, reported twice: once as the field, once as the identity it breaks. */
    $this->artisan('ledger:verify')
        ->expectsOutputToContain('4001')
        ->expectsOutputToContain('7001')
        ->assertExitCode(1);
});

it('catches a snapshot that does not add up, even before comparing it to the ledger', function (): void {
    $instructor = Instructor::factory()->create();
    seedLedger($instructor);

    DB::table('instructor_balances')
        ->where('instructor_id', $instructor->id)
        ->update(['reserved_minor' => 2_000]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('outstanding = available + held + reserved')
        ->assertExitCode(1);
});

it('catches a ledger that does not sum to zero', function (): void {
    LedgerEntry::factory()->create(['amount_minor' => 500]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('ledger sum')
        ->expectsOutputToContain('transaction sum')
        ->assertExitCode(1);
});

it('catches money owed to an instructor with no snapshot row at all', function (): void {
    $instructor = Instructor::factory()->create();
    seedLedger($instructor);

    InstructorBalance::query()->whereKey($instructor->id)->delete();

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('missing snapshot')
        ->assertExitCode(1);
});

it('passes on an instructor who has earned nothing', function (): void {
    InstructorBalance::factory()->create();

    $this->artisan('ledger:verify')->assertExitCode(0);
});

it('exits 2 when --instructor names a real instructor who has no snapshot row (R21)', function (): void {
    $withBalance = Instructor::factory()->create();
    $withoutBalance = Instructor::factory()->create();

    seedLedger($withBalance);

    /** The instructor exists; the run still has nothing to prove about them. */
    $this->artisan('ledger:verify', ['--instructor' => (string) $withoutBalance->id])
        ->expectsOutputToContain('checked nothing')
        ->assertExitCode(2);

    /** And the whole-ledger run over the same data is still green. */
    $this->artisan('ledger:verify')->assertExitCode(0);
});

it('scopes to one instructor, so a healthy one stays green while another is broken', function (): void {
    $broken = Instructor::factory()->create();
    $healthy = Instructor::factory()->create();

    seedLedger($broken);
    seedLedger($healthy);

    DB::table('instructor_balances')
        ->where('instructor_id', $broken->id)
        ->update(['paid_minor' => 99]);

    $this->artisan('ledger:verify', ['--instructor' => (string) $healthy->id])
        ->expectsOutputToContain("instructor {$healthy->id}")
        ->assertExitCode(0);

    $this->artisan('ledger:verify', ['--instructor' => (string) $broken->id])
        ->expectsOutputToContain('paid_minor')
        ->assertExitCode(1);
});

it('stops at the first mismatch with --fail-fast', function (): void {
    $first = Instructor::factory()->create();
    $second = Instructor::factory()->create();

    seedLedger($first);
    seedLedger($second);

    DB::table('instructor_balances')->update([
        'earned_minor' => 1,
        'paid_minor' => 2,
    ]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('6 mismatch(es)')
        ->assertExitCode(1);

    $this->artisan('ledger:verify', ['--fail-fast' => true])
        ->expectsOutputToContain('1 mismatch(es), stopped at the first')
        ->assertExitCode(1);
});

it('refuses an --instructor option that is not an id', function (): void {
    expect(fn () => $this->artisan('ledger:verify', ['--instructor' => 'seven'])->run())
        ->toThrow(InvalidArgumentException::class, '--instructor must be a positive integer');
});
