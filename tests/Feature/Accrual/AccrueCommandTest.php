<?php

declare(strict_types=1);

use App\Enums\AccrualPeriodStatus;
use App\Jobs\AccruePeriodsChunkJob;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

/*
 * The command end to end: which periods a run picks up, what running it three
 * times produces, and what it refuses.
 *
 * The acceptance criterion this file exists for is the first one in F05:
 * `ledger:accrue` three times leaves identical allocations, ledger rows and
 * snapshot. Idempotency here rests on the compare-and-set and the unique
 * indexes, not on the cache lock — which is why nothing below touches the lock.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * An annual term bought a year ago, so eleven of its twelve periods have
 * closed and each has engagement from two instructors.
 *
 * @return array{0: int, 1: list<Instructor>}
 */
function annualTermReadyToAccrue(string $externalRef): array
{
    $plan = Plan::factory()->create([
        'interval_months' => 12,
        'price_minor' => 300_000,
    ]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        $externalRef,
        capturedAt: CarbonImmutable::now(),
    );

    $instructors = [Instructor::factory()->create(), Instructor::factory()->create()];

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructors[0], 300);
        engage($outcome->subscriptionId, $period, $instructors[1], 100);
    }

    return [$outcome->subscriptionId, $instructors];
}

it('recognizes every period whose term has closed, and leaves the rest scheduled', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    [$subscriptionId] = annualTermReadyToAccrue('ch_accrue_0001');

    /** Eleven months on: periods 1-11 have ended, the twelfth has not. */
    $this->travelTo(CarbonImmutable::parse('2024-12-01 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])
        ->expectsOutputToContain('found 11 due period(s)')
        ->expectsOutputToContain('Recognized 11')
        ->assertSuccessful();

    $periods = AccrualPeriod::query()->where('subscription_id', $subscriptionId)->orderBy('sequence')->get();

    expect($periods->where('status', AccrualPeriodStatus::RECOGNIZED))->toHaveCount(11)
        ->and($periods->last()->status)->toBe(AccrualPeriodStatus::SCHEDULED)
        ->and($periods->last()->pool_minor)->toBeNull();
});

it('leaves identical allocations, ledger rows and balances when run three times', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    [, $instructors] = annualTermReadyToAccrue('ch_accrue_0002');

    $this->travelTo(CarbonImmutable::parse('2024-12-01 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])->assertSuccessful();

    $fingerprint = fn (): array => [
        'allocations' => EarningAllocation::query()->orderBy('id')->get(['accrual_period_id', 'instructor_id', 'amount_minor', 'available_at'])->toArray(),
        'entries' => LedgerEntry::query()->orderBy('id')->get(['account_type', 'account_id', 'amount_minor', 'entry_type', 'reference_id'])->toArray(),
        'balances' => InstructorBalance::query()->orderBy('instructor_id')->get(['earned_minor', 'held_minor', 'available_minor'])->toArray(),
    ];

    $afterFirstRun = $fingerprint();

    $this->artisan('ledger:accrue', ['--sync' => true])
        ->expectsOutputToContain('found 0 due period(s)')
        ->assertSuccessful();

    $this->artisan('ledger:accrue', ['--sync' => true])->assertSuccessful();

    expect($fingerprint())->toBe($afterFirstRun);

    /** And the money is the money: 70% of eleven twelfths, split 300:100. */
    $earned = InstructorBalance::query()->sum('earned_minor');
    $recognizedGross = (int) AccrualPeriod::query()->where('status', AccrualPeriodStatus::RECOGNIZED)->sum('gross_minor');

    expect((int) $earned)->toBe((int) EarningAllocation::query()->sum('amount_minor'))
        ->and(InstructorBalance::query()->count())->toBe(count($instructors))
        ->and($recognizedGross)->toBeGreaterThan(0);
});

it('releases matured earnings at the end of the run', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    [, $instructors] = annualTermReadyToAccrue('ch_accrue_0003');

    /**
     * Two and a half months on: periods 1 and 2 have closed (1 Feb and 1 Mar)
     * and both holds expired a week later, so all four of their allocations —
     * two instructors each — mature in the same run that recognizes them.
     */
    $this->travelTo(CarbonImmutable::parse('2024-03-20 09:00:00'));

    $this->artisan('ledger:accrue', ['--sync' => true])
        ->expectsOutputToContain('found 2 due period(s)')
        ->expectsOutputToContain('Released 4 matured allocation(s)')
        ->assertSuccessful();

    $balance = InstructorBalance::query()->findOrFail($instructors[0]->id);

    expect($balance->available_minor)->toBeGreaterThan(0)
        ->and($balance->held_minor)->toBe(0);
});

it('backfills when given a past date, and recognizes nothing later than it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    [$subscriptionId] = annualTermReadyToAccrue('ch_accrue_0004');

    $this->travelTo(CarbonImmutable::parse('2024-12-01 09:00:00'));

    /** As if the run had happened on 1 May: only periods ending by then are due. */
    $this->artisan('ledger:accrue', ['--date' => '2024-05-01', '--sync' => true])
        ->expectsOutputToContain('found 4 due period(s)')
        ->assertSuccessful();

    expect(AccrualPeriod::query()->where('subscription_id', $subscriptionId)
        ->where('status', AccrualPeriodStatus::RECOGNIZED)->count())->toBe(4);
});

it('refuses a date in the future', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $this->artisan('ledger:accrue', ['--date' => '2024-06-16', '--sync' => true])
        ->expectsOutputToContain('--date cannot be in the future')
        ->assertExitCode(2);

    /** Today is fine — it is tomorrow that has not happened. */
    $this->artisan('ledger:accrue', ['--date' => '2024-06-15', '--sync' => true])->assertSuccessful();
});

it('refuses a chunk size that is not a positive integer', function (): void {
    $this->artisan('ledger:accrue', ['--chunk' => '0', '--sync' => true])
        ->expectsOutputToContain('--chunk must be a positive integer')
        ->assertExitCode(2);
});

it('dispatches one chunk job per keyset page when not running inline', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-01-01 09:00:00'));

    annualTermReadyToAccrue('ch_accrue_0005');

    $this->travelTo(CarbonImmutable::parse('2024-12-01 09:00:00'));

    Bus::fake();

    $this->artisan('ledger:accrue', ['--chunk' => '4'])
        ->expectsOutputToContain('Dispatched 11 period(s)')
        ->assertSuccessful();

    /** 11 due periods at 4 per page: three jobs, batched. */
    Bus::assertBatchCount(1);

    /** Nothing was recognized here — the workers have not run. */
    expect(AccrualPeriod::query()->where('status', AccrualPeriodStatus::RECOGNIZED)->count())->toBe(0)
        ->and(class_exists(AccruePeriodsChunkJob::class))->toBeTrue();
});
