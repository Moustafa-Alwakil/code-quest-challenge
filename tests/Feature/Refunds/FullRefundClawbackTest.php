<?php

declare(strict_types=1);

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutItem;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
 * The rare path: money that has already been earned, and sometimes already
 * paid, has to come back.
 *
 * Two things are under test here and they pull in opposite directions. The
 * clawback must be *exact* — every reversal is an allocation's own recorded
 * amount, never a recomputed share, because re-running the split could land a
 * piastre elsewhere and invent money. And it must come from the *right place*:
 * earnings still inside their hold cost the instructor nothing (D-6), while
 * released ones come out of `available` and may leave them owing the platform
 * (D-7).
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * A term whose earnings are all still inside their hold window.
 *
 * @return array{0: int, 1: Instructor}
 */
function recentlyEarnedTerm(string $externalRef): array
{
    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => 30_000]);

    /** One period, closed two days ago, so its allocation is held not released. */
    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        $externalRef,
        capturedAt: CarbonImmutable::now()->subDays(32),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    return [$outcome->subscriptionId, $instructor];
}

it('claws back held earnings for free, leaving available untouched', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId, $instructor] = recentlyEarnedTerm('ch_full_0001');

    $before = InstructorBalance::query()->findOrFail($instructor->id);

    expect($before->held_minor)->toBeGreaterThan(0)
        ->and($before->available_minor)->toBe(0);

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_full_0001',
        '--full' => true,
    ])->assertSuccessful();

    $after = InstructorBalance::query()->findOrFail($instructor->id);

    /**
     * The whole point of the hold (D-6): the money had not become payable, so
     * taking it back leaves the instructor exactly where they were rather than
     * in debt.
     */
    expect($after->held_minor)->toBe(0)
        ->and($after->available_minor)->toBe(0)
        ->and($after->clawed_back_minor)->toBe($before->held_minor)
        ->and($after->outstandingMinor())->toBe(0);

    expect(EarningAllocation::query()->whereNull('clawed_back_at')->count())->toBe(0)
        ->and(Refund::query()->firstOrFail()->amount_minor)->toBe(30_000);
});

it('reverses each allocation by its own amount, never by a recomputed share', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    /** A pool of 21 001 across three equal weights: one instructor gets the odd piastre. */
    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => 30_002]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_full_0002',
        capturedAt: CarbonImmutable::now()->subDays(32),
    );

    $instructors = [Instructor::factory()->create(), Instructor::factory()->create(), Instructor::factory()->create()];

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        foreach ($instructors as $index => $instructor) {
            engage($outcome->subscriptionId, $period, $instructor, [3, 3, 3][$index]);
        }
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    $allocations = EarningAllocation::query()->orderBy('instructor_id')->pluck('amount_minor', 'instructor_id');

    /** 3/3/3 of the pool: one instructor has a piastre more than the others. */
    expect($allocations->unique())->toHaveCount(2);

    $this->artisan('refunds:issue', [
        'subscription' => (string) $outcome->subscriptionId,
        '--external-ref' => 're_full_0002',
        '--full' => true,
    ])->assertSuccessful();

    foreach ($instructors as $instructor) {
        $balance = InstructorBalance::query()->findOrFail($instructor->id);

        /** Each reversal equals that instructor's own allocation, to the piastre. */
        expect($balance->clawed_back_minor)->toBe($allocations[$instructor->id])
            ->and($balance->outstandingMinor())->toBe(0);
    }

    /** And the refund is the price exactly — no piastre created or lost. */
    expect(Refund::query()->firstOrFail()->amount_minor)->toBe(30_002);
});

it('posts one clawback transaction with one leg per account', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    /** Three months, so each instructor earned from three separate periods. */
    $plan = Plan::factory()->create(['interval_months' => 3, 'price_minor' => 80_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_full_0003',
        capturedAt: CarbonImmutable::now()->subMonths(4),
    );

    $alice = Instructor::factory()->create();
    $bob = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $alice, 300);
        engage($outcome->subscriptionId, $period, $bob, 100);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    expect(EarningAllocation::query()->count())->toBe(6);

    $this->artisan('refunds:issue', [
        'subscription' => (string) $outcome->subscriptionId,
        '--external-ref' => 're_full_0003',
        '--full' => true,
    ])->assertSuccessful();

    $legs = LedgerEntry::query()->where('entry_type', LedgerEntryType::REFUND_CLAWBACK)->get();

    /**
     * Six allocations, but four legs: one per instructor, one for the
     * platform's cut and one for cash. A transaction may not touch an account
     * twice, so the aggregation is a correctness requirement rather than tidiness.
     */
    expect($legs)->toHaveCount(4)
        ->and($legs->pluck('transaction_uuid')->unique())->toHaveCount(1)
        ->and($legs->sum('amount_minor'))->toBe(0)
        ->and($legs->where('account_type', LedgerAccountType::INSTRUCTOR_PAYABLE))->toHaveCount(2);

    /** Every account this term ever touched returns to zero. */
    expect(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $outcome->subscriptionId))->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::INSTRUCTOR_PAYABLE, $alice->id))->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::INSTRUCTOR_PAYABLE, $bob->id))->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_REVENUE, 0))->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_CASH, 0))->toBe(0);
});

it('drives available negative when the earnings had already been released', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    /** An older term, so its holds have long expired and the money is payable. */
    $plan = Plan::factory()->create(['interval_months' => 3, 'price_minor' => 80_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_full_0004',
        capturedAt: CarbonImmutable::now()->subMonths(4),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    $before = InstructorBalance::query()->findOrFail($instructor->id);

    expect($before->available_minor)->toBeGreaterThan(0)
        ->and($before->held_minor)->toBe(0);

    $this->artisan('refunds:issue', [
        'subscription' => (string) $outcome->subscriptionId,
        '--external-ref' => 're_full_0004',
        '--full' => true,
    ])->assertSuccessful();

    $after = InstructorBalance::query()->findOrFail($instructor->id);

    /** D-7: the debt carries forward rather than being chased. */
    expect($after->available_minor)->toBe(0)
        ->and($after->clawed_back_minor)->toBe($before->available_minor)
        ->and($after->outstandingMinor())->toBe(0);
});

it('leaves a reserved payout alone and nets the debt against the next run', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $plan = Plan::factory()->create(['interval_months' => 3, 'price_minor' => 80_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_full_0005',
        capturedAt: CarbonImmutable::now()->subMonths(4),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    /** The money is paid out before the refund arrives. */
    $this->artisan('payouts:run', ['--run-key' => 'payout:before-refund'])->assertSuccessful();

    $item = PayoutItem::query()->firstOrFail();
    $paid = InstructorBalance::query()->findOrFail($instructor->id)->paid_minor;

    expect($paid)->toBeGreaterThan(0);

    $this->artisan('refunds:issue', [
        'subscription' => (string) $outcome->subscriptionId,
        '--external-ref' => 're_full_0005',
        '--full' => true,
    ])->assertSuccessful();

    $after = InstructorBalance::query()->findOrFail($instructor->id);

    /**
     * The payout item is untouched: it paid what it reserved, and it was owed
     * at the moment it was reserved. The clawback lands on `available`, which
     * goes negative and carries forward.
     */
    expect($item->refresh()->amount_minor)->toBe($paid)
        ->and($after->paid_minor)->toBe($paid)
        ->and($after->available_minor)->toBeLessThan(0);

    /** And the next payout run skips them until the balance is positive again. */
    $this->artisan('payouts:run', ['--run-key' => 'payout:after-refund'])->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(1);
});
