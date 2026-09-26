<?php

declare(strict_types=1);

use App\Actions\Accrual\RecognizeAccrualPeriodAction;
use App\DTOs\Accrual\RecognizePeriodData;
use App\Enums\AccrualPeriodStatus;
use App\Enums\ZeroEngagementPolicy;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\User;
use App\Support\Accrual\RecognitionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Tests\Support\Interleaved;

/*
 * F05's edge case: "two servers recognize the same period — CAS, one wins, the
 * other no-ops."
 *
 * Not transaction-wrapped (R11). Under RefreshDatabase the second session could
 * not see the first's uncommitted status change, so both would read
 * `scheduled`, both would "win", and the test would pass having proven the
 * opposite of what it claims.
 *
 * What is under test is the compare-and-set alone. No cache lock is taken
 * anywhere in this file: if Redis vanished, a period would still be recognized
 * exactly once, and that is the property worth having.
 */

beforeEach(function (): void {
    Interleaved::open();
});

afterEach(function (): void {
    Interleaved::close();
    assertLedgerBalanced();
});

/**
 * A scheduled period with engagement behind it, committed before the race
 * starts so both sessions are looking at the same durable row.
 *
 * @return array{0: AccrualPeriod, 1: Instructor}
 */
function raceablePeriod(): array
{
    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => 30_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_race_0001',
        capturedAt: CarbonImmutable::now(),
    );

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $instructor = Instructor::factory()->create();

    Engagement::query()->create([
        'subscription_id' => $outcome->subscriptionId,
        'period_start' => $period->period_start->toDateString(),
        'instructor_id' => $instructor->id,
        'units' => 60,
    ]);

    return [$period, $instructor];
}

function recognize(int $periodId): RecognitionOutcome
{
    return app(RecognizeAccrualPeriodAction::class)(RecognizePeriodData::forPeriod(
        $periodId,
        7_000,
        7,
        'EGP',
        ZeroEngagementPolicy::PLATFORM_RETAINS,
        CarbonImmutable::now(),
    ));
}

it('lets only one of two sessions recognize the same period', function (): void {
    [$period, $instructor] = raceablePeriod();

    $a = Interleaved::session(Interleaved::SESSION_A);

    /** A claims the period and holds the row, uncommitted. */
    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $period): void {
        $a->beginTransaction();

        expect(recognize($period->id)->recognized)->toBeTrue();
    });

    /**
     * B's compare-and-set targets the same row. InnoDB makes it wait for the
     * exclusive lock A holds rather than reading the stale `scheduled` value —
     * which is precisely why the CAS is sufficient on its own.
     */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($period): void {
        $b->beginTransaction();
        recognize($period->id);
    });

    expect($blocked)->not->toBeNull('Session B recognized a period session A had already claimed.')
        ->and($blocked->getMessage())->toContain('Lock wait timeout');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** With A committed, B now sees `recognized` and the CAS affects no rows. */
    $second = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): RecognitionOutcome => $b->transaction(fn (): RecognitionOutcome => recognize($period->id)),
    );

    expect($second->recognized)->toBeFalse()
        ->and($second->allocationCount)->toBe(0);

    /** One recognition: one set of allocations, one posting, one balance. */
    expect(EarningAllocation::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('reference_type', 'accrual_period')->count())->toBe(3)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->earned_minor)->toBe(21_000)
        ->and($period->refresh()->status)->toBe(AccrualPeriodStatus::RECOGNIZED);
});

it('leaves the period recognizable when the winning session rolls back', function (): void {
    [$period, $instructor] = raceablePeriod();

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $period): void {
        $a->beginTransaction();

        expect(recognize($period->id)->recognized)->toBeTrue();
    });

    /** The winner crashes. Without a separate `recognizing` status (R3), nothing is stranded. */
    Interleaved::as(Interleaved::SESSION_A, fn () => $a->rollBack());

    $retry = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): RecognitionOutcome => $b->transaction(fn (): RecognitionOutcome => recognize($period->id)),
    );

    expect($retry->recognized)->toBeTrue()
        ->and($retry->poolMinor)->toBe(21_000)
        ->and(EarningAllocation::query()->count())->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->held_minor)->toBe(21_000);
});
