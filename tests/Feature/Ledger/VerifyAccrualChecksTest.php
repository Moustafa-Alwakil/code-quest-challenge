<?php

declare(strict_types=1);

use App\Actions\Ledger\VerifyLedgerAction;
use App\DTOs\Ledger\VerifyLedgerData;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The three things `ledger:verify` learned to check once F05 gave it something
 * to check them against: `held` (recomputed from allocation rows, R2/R20),
 * check 5 (a term's liability against the time it has not delivered) and
 * check 6 (a recognized period's gross against how it was divided).
 *
 * Each test below breaks exactly one of them, in the database, behind the
 * application's back — because that is the only way to prove the verifier is
 * looking rather than merely agreeing with whatever wrote the row.
 *
 * `assertLedgerBalanced()` is not registered: the subject here *is* a red
 * verifier. `ledgerIsClean()` is the boolean form these tests need.
 *
 * Which row a check produced is asserted through the action rather than through
 * the rendered table. `expectsOutputToContain()` matches one console write per
 * expectation, in order, so two substrings from a single table row can never
 * both match — a limitation of the assertion, not of the output.
 */

/**
 * The mismatches a full verification run found, as rows.
 *
 * @return list<array{check: string, scope: string, field: string, expected: string, actual: string, delta: string}>
 */
function verificationRows(): array
{
    return app(VerifyLedgerAction::class)(VerifyLedgerData::everything())->toRows();
}

/**
 * An annual term with two thirds of its periods recognized and one instructor
 * behind every allocation.
 *
 * @return array{0: int, 1: Instructor}
 */
function verifiableAccrual(string $externalRef): array
{
    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => 300_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        $externalRef,
        capturedAt: CarbonImmutable::now(),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 90);
    }

    return [$outcome->subscriptionId, $instructor];
}

it('passes over a real accrual run, having actually looked at it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    [$subscriptionId] = verifiableAccrual('ch_verify_0001');

    $this->travelTo(CarbonImmutable::parse('2024-09-01 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])->assertSuccessful();

    expect(ledgerIsClean())->toBeTrue()
        /** Half-recognized is the interesting state: the liability is partly discharged. */
        ->and(AccrualPeriod::query()->where('subscription_id', $subscriptionId)->where('status', 'scheduled')->count())
        ->toBeGreaterThan(0);

    $this->artisan('ledger:verify')->assertSuccessful();
});

it('fails when an allocation amount is edited behind the ledger', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    verifiableAccrual('ch_verify_0002');

    $this->travelTo(CarbonImmutable::parse('2024-09-01 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])->assertSuccessful();

    /**
     * One piastre off, on one row, with no ledger entry to match it — and on a
     * row still *held*, so both checks that depend on it move. Editing a
     * released allocation would prove only half of this: released rows are out
     * of the `held` recomputation by definition.
     */
    $allocation = EarningAllocation::query()->whereNull('released_at')->orderBy('id')->firstOrFail();

    DB::table('earning_allocations')->where('id', $allocation->id)->update([
        'amount_minor' => $allocation->amount_minor + 1,
    ]);

    expect(ledgerIsClean())->toBeFalse();

    /** Check 6 catches the division; check 3 catches `held` drifting with it. */
    $this->artisan('ledger:verify')
        ->expectsOutputToContain('period split')
        ->expectsOutputToContain('held_minor')
        ->assertExitCode(1);
});

it('fails when a recognized period claims a platform cut it did not take', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    verifiableAccrual('ch_verify_0003');

    $this->travelTo(CarbonImmutable::parse('2024-09-01 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])->assertSuccessful();

    $period = AccrualPeriod::query()->where('status', 'recognized')->orderBy('id')->firstOrFail();

    DB::table('accrual_periods')->where('id', $period->id)->update([
        'platform_minor' => $period->platform_minor + 500,
    ]);

    /**
     * Nothing else notices this: the ledger still sums to zero, every snapshot
     * still matches, and the allocations are untouched. Only check 6 compares
     * the division against the gross it divided.
     */
    expect(verificationRows())->toBe([[
        'check' => 'period split',
        'scope' => "accrual period {$period->id}",
        'field' => 'platform + sum(allocations)',
        'expected' => (string) $period->gross_minor,
        'actual' => (string) ($period->gross_minor + 500),
        'delta' => '+500',
    ]]);

    $this->artisan('ledger:verify')->assertExitCode(1);
});

it('fails when a term owes less deferred revenue than it has undelivered time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    [$subscriptionId] = verifiableAccrual('ch_verify_0004');

    $this->travelTo(CarbonImmutable::parse('2024-09-01 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])->assertSuccessful();

    /** A period marked recognized without anything recognizing it. */
    $scheduled = AccrualPeriod::query()
        ->where('subscription_id', $subscriptionId)
        ->where('status', 'scheduled')
        ->orderBy('sequence')
        ->firstOrFail();

    $stillScheduledGross = (int) AccrualPeriod::query()
        ->where('subscription_id', $subscriptionId)
        ->where('status', 'scheduled')
        ->where('id', '!=', $scheduled->id)
        ->sum('gross_minor');

    DB::table('accrual_periods')->where('id', $scheduled->id)->update(['status' => 'recognized']);

    $rows = collect(verificationRows());

    $deferred = $rows->firstWhere('check', 'deferred revenue');

    expect($deferred)->not->toBeNull()
        ->and($deferred['scope'])->toBe("subscription {$subscriptionId}")
        /** The term still owes the periods it has genuinely not delivered. */
        ->and($deferred['expected'])->toBe((string) $stillScheduledGross)
        ->and($deferred['actual'])->toBe((string) ($stillScheduledGross + $scheduled->gross_minor));

    $this->artisan('ledger:verify')->assertExitCode(1);
});

it('fails when a snapshot holds money no allocation row accounts for', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    [, $instructor] = verifiableAccrual('ch_verify_0005');

    $this->travelTo(CarbonImmutable::parse('2024-09-01 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])->assertSuccessful();

    /**
     * This is the case F03 could not detect at all: with `held` recomputing to
     * 0, any value here agreed with nothing. It is now a red run.
     */
    DB::table('instructor_balances')->where('instructor_id', $instructor->id)->update([
        'held_minor' => DB::raw('held_minor + 1000'),
        'available_minor' => DB::raw('available_minor - 1000'),
    ]);

    expect(ledgerIsClean($instructor->id))->toBeFalse();

    $this->artisan('ledger:verify', ['--instructor' => $instructor->id])
        ->expectsOutputToContain('held_minor')
        ->assertExitCode(1);
});
