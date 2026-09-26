<?php

declare(strict_types=1);

use App\Actions\Accrual\ReleaseMaturedEarningsAction;
use App\DTOs\Accrual\ReleaseMaturedEarningsData;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * D-6, and the feature that retires `HeldBalanceGapTest`.
 *
 * F03 shipped `available = payable owed − held` with `held` provably zero (R20),
 * because the hold had nowhere to live until `earning_allocations` arrived. Now
 * it does, and these are the tests that R20 promised: money is earned into
 * `held`, matures on the calendar into `available`, and a second sweep over the
 * same rows moves nothing.
 *
 * The release posts no ledger entry on purpose (R2) — the money was already
 * owed at recognition. That is asserted directly below, because "no rows
 * written" is easy to break by accident and impossible to notice afterwards.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

function releaseMaturedAt(CarbonImmutable $asOf, int $chunkSize = 1000): int
{
    return app(ReleaseMaturedEarningsAction::class)(
        ReleaseMaturedEarningsData::asOf($asOf, 'EGP', $chunkSize)
    );
}

/**
 * A recognized monthly term with one instructor holding the whole pool.
 *
 * @return array{0: int, 1: Instructor, 2: AccrualPeriod}
 */
function heldEarningsFor(string $externalRef, int $priceMinor = 30_000): array
{
    /** A random plan key, not the `monthly()` state: UNIQUE `key` refuses a second one. */
    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => $priceMinor]);

    /**
     * Captured *now*, so `travelTo` actually moves the term. The shared
     * `recordCapturedPayment` helper defaults to a fixed January date, which
     * would give two terms bought a fortnight apart the same period and the
     * same hold — and quietly pass a test about maturing at different times.
     */
    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        $externalRef,
        capturedAt: CarbonImmutable::now(),
    );

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $instructor = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $instructor, 60);

    return [$outcome->subscriptionId, $instructor, recognizeFirstPeriodOf($outcome->subscriptionId)];
}

it('keeps earnings out of available until the hold expires', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    [, $instructor, $period] = heldEarningsFor('ch_hold_0001');

    $availableAt = $period->period_end->addDays(7);

    /** One second before maturity: still held, and a payout run would see nothing. */
    $released = releaseMaturedAt($availableAt->subSecond());

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($released)->toBe(0)
        ->and($balance->held_minor)->toBe(21_000)
        ->and($balance->available_minor)->toBe(0)
        ->and(EarningAllocation::query()->firstOrFail()->released_at)->toBeNull();
});

it('moves earnings into available once the hold has expired', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    [, $instructor, $period] = heldEarningsFor('ch_hold_0002');

    $ledgerCount = LedgerEntry::query()->count();

    $released = releaseMaturedAt($period->period_end->addDays(7));

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($released)->toBe(1)
        ->and($balance->held_minor)->toBe(0)
        ->and($balance->available_minor)->toBe(21_000)
        /** Earned and outstanding are unchanged: the money did not appear, it moved. */
        ->and($balance->earned_minor)->toBe(21_000)
        ->and($balance->outstandingMinor())->toBe(21_000)
        ->and(EarningAllocation::query()->firstOrFail()->released_at)->not->toBeNull()
        /** No ledger entry accompanies a release — the hold is not a ledger fact (R2). */
        ->and(LedgerEntry::query()->count())->toBe($ledgerCount);
});

it('releases nothing the second and third time over the same rows', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    [, $instructor, $period] = heldEarningsFor('ch_hold_0003');

    $matured = $period->period_end->addDays(7);

    expect(releaseMaturedAt($matured))->toBe(1)
        ->and(releaseMaturedAt($matured))->toBe(0)
        ->and(releaseMaturedAt($matured->addYear()))->toBe(0);

    /** A released row no longer matches the WHERE, so the balance cannot double. */
    expect(InstructorBalance::query()->findOrFail($instructor->id)->available_minor)->toBe(21_000);
});

it('releases only the allocations that have actually matured', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    [, $early] = heldEarningsFor('ch_hold_0004');

    /** A second term bought a fortnight later: its period, and its hold, end later. */
    $this->travelTo(CarbonImmutable::parse('2024-06-29 09:00:00'));

    [, $late, $latePeriod] = heldEarningsFor('ch_hold_0005');

    $earlyPeriod = AccrualPeriod::query()->where('status', 'recognized')->orderBy('id')->firstOrFail();

    $released = releaseMaturedAt($earlyPeriod->period_end->addDays(7));

    expect($released)->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($early->id)->available_minor)->toBe(21_000)
        ->and(InstructorBalance::query()->findOrFail($late->id)->available_minor)->toBe(0)
        ->and(InstructorBalance::query()->findOrFail($late->id)->held_minor)->toBe(21_000);

    /** Once the later hold expires too, the rest follows. */
    expect(releaseMaturedAt($latePeriod->period_end->addDays(7)))->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($late->id)->available_minor)->toBe(21_000);
});

it('sweeps more rows than one chunk holds', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $periodEnd = null;

    foreach (range(1, 5) as $index) {
        [, , $period] = heldEarningsFor("ch_hold_chunk_{$index}");
        $periodEnd = $period->period_end;
    }

    /** Chunk of 2 over 5 matured rows: the loop has to come back for the rest. */
    expect(releaseMaturedAt($periodEnd->addDays(7), chunkSize: 2))->toBe(5)
        ->and(EarningAllocation::query()->whereNull('released_at')->count())->toBe(0);
});
