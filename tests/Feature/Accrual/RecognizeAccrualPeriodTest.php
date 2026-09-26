<?php

declare(strict_types=1);

use App\Actions\Accrual\RecognizeAccrualPeriodAction;
use App\DTOs\Accrual\RecognizePeriodData;
use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\ZeroEngagementPolicy;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Recognition is the only place instructor money is created, so this file
 * proves the whole movement and not merely that it ran: the period's status,
 * the split it recorded, the allocation rows that *are* the hold (R2), the
 * three-way posting, and the snapshot deltas that go with it.
 *
 * Every subscription here is built through SubscribeStudentAction, never a
 * factory, so the deferred-revenue liability recognition discharges is real
 * (F02, Factories).
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

function recognizeFirstPeriodOf(int $subscriptionId, int $shareBps = 7_000, int $holdDays = 7): AccrualPeriod
{
    $period = AccrualPeriod::query()
        ->where('subscription_id', $subscriptionId)
        ->orderBy('sequence')
        ->firstOrFail();

    app(RecognizeAccrualPeriodAction::class)(RecognizePeriodData::forPeriod(
        $period->id,
        $shareBps,
        $holdDays,
        'EGP',
        ZeroEngagementPolicy::PLATFORM_RETAINS,
        CarbonImmutable::now(),
    ));

    return $period->refresh();
}

function engage(int $subscriptionId, AccrualPeriod $period, Instructor $instructor, int $units): void
{
    Engagement::query()->create([
        'subscription_id' => $subscriptionId,
        'period_start' => $period->period_start->toDateString(),
        'instructor_id' => $instructor->id,
        'units' => $units,
    ]);
}

it('splits a period between the platform and the instructors who earned it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_recognize_0001');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();

    $alice = Instructor::factory()->create();
    $bob = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $alice, 300);
    engage($outcome->subscriptionId, $period, $bob, 100);

    $recognized = recognizeFirstPeriodOf($outcome->subscriptionId);

    /** 70% of 30 000 is 21 000 to the pool; the platform keeps the other 9 000. */
    expect($recognized->status)->toBe(AccrualPeriodStatus::RECOGNIZED)
        ->and($recognized->pool_minor)->toBe(21_000)
        ->and($recognized->platform_minor)->toBe(9_000)
        ->and($recognized->recognized_at)->not->toBeNull();

    /** 300:100 of 21 000 is 15 750 and 5 250 — exact, no remainder to place. */
    $allocations = EarningAllocation::query()->orderBy('instructor_id')->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->amount_minor)->toBe(15_750)
        ->and($allocations[0]->weight_units)->toBe(300)
        ->and($allocations[1]->amount_minor)->toBe(5_250)
        ->and($allocations[1]->weight_units)->toBe(100)
        /** The hold runs from the period's end, not from the moment of recognition (D-6). */
        ->and($allocations[0]->available_at->toDateTimeString())
        ->toBe($recognized->period_end->addDays(7)->toDateTimeString())
        ->and($allocations[0]->released_at)->toBeNull();
});

it('posts one balanced transaction: the liability becomes revenue and what is owed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_recognize_0002');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $alice = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $alice, 60);

    recognizeFirstPeriodOf($outcome->subscriptionId);

    $legs = LedgerEntry::query()
        ->where('entry_type', LedgerEntryType::PERIOD_RECOGNIZED)
        ->where('reference_type', 'accrual_period')
        ->where('reference_id', $period->id)
        ->get();

    expect($legs)->toHaveCount(3)
        /** One transaction, so one uuid, and it sums to zero. */
        ->and($legs->pluck('transaction_uuid')->unique())->toHaveCount(1)
        ->and($legs->sum('amount_minor'))->toBe(0)
        /** Debit the liability the payment created, credit where the money went. */
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $outcome->subscriptionId))->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_REVENUE, 0))->toBe(-9_000)
        ->and(ledgerSumFor(LedgerAccountType::INSTRUCTOR_PAYABLE, $alice->id))->toBe(-21_000);
});

it('earns the money into held, not into available', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_recognize_0003');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $alice = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $alice, 60);

    recognizeFirstPeriodOf($outcome->subscriptionId);

    $balance = InstructorBalance::query()->findOrFail($alice->id);

    /**
     * The whole point of D-6: earned immediately, payable later. A payout run
     * reading `available_minor` right now finds nothing to reserve.
     */
    expect($balance->earned_minor)->toBe(21_000)
        ->and($balance->held_minor)->toBe(21_000)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->outstandingMinor())->toBe(21_000)
        ->and($balance->currency)->toBe('EGP');
});

it('is a no-op the second time, and the third', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_recognize_0004');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $alice = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $alice, 60);

    recognizeFirstPeriodOf($outcome->subscriptionId);

    $ledgerCount = LedgerEntry::query()->count();

    recognizeFirstPeriodOf($outcome->subscriptionId);
    recognizeFirstPeriodOf($outcome->subscriptionId);

    /** The CAS found the period already recognized, so nothing was written. */
    expect(LedgerEntry::query()->count())->toBe($ledgerCount)
        ->and(EarningAllocation::query()->count())->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($alice->id)->earned_minor)->toBe(21_000);
});

it('ignores engagement recorded after the period was recognized', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_recognize_0006');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $alice = Instructor::factory()->create();
    $latecomer = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $alice, 60);

    recognizeFirstPeriodOf($outcome->subscriptionId);

    /** The rollup job ran late. F02's documented late-data policy: too late. */
    engage($outcome->subscriptionId, $period, $latecomer, 600);

    recognizeFirstPeriodOf($outcome->subscriptionId);

    expect(EarningAllocation::query()->count())->toBe(1)
        ->and(EarningAllocation::query()->firstOrFail()->instructor_id)->toBe($alice->id)
        ->and(InstructorBalance::query()->find($latecomer->id))->toBeNull();
});
