<?php

declare(strict_types=1);

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * D-3: a subscription that generated no engagement in a period credits nobody,
 * and the platform recognizes the whole gross.
 *
 * The weight denominator is zero, so there is no defensible proportion —
 * `Allocator` would throw on it, correctly, because deciding what zero
 * engagement *means* is a policy call and not an arithmetic one. These tests
 * prove the call is made here, at recognition, and that the ledger still
 * balances with only two legs.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('gives a dormant period entirely to the platform', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_dormant_0001');

    /** Enrolled, but nobody watched anything. */
    Instructor::factory()->create();

    $period = recognizeFirstPeriodOf($outcome->subscriptionId);

    expect($period->pool_minor)->toBe(0)
        ->and($period->platform_minor)->toBe(30_000)
        ->and(EarningAllocation::query()->count())->toBe(0)
        ->and(InstructorBalance::query()->count())->toBe(0);

    $legs = LedgerEntry::query()
        ->where('entry_type', LedgerEntryType::PERIOD_RECOGNIZED)
        ->where('reference_id', $period->id)
        ->get();

    /** Two legs, not three: there is no instructor side to write. */
    expect($legs)->toHaveCount(2)
        ->and($legs->sum('amount_minor'))->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_REVENUE, 0))->toBe(-30_000)
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $outcome->subscriptionId))->toBe(0);
});

it('treats a rollup row of zero units as no engagement at all', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_dormant_0002');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $dormant = Instructor::factory()->create();

    /**
     * A rollup job that writes a row per enrolled instructor, zero included,
     * must not turn D-3 into "split nothing five ways" — or worse, hand the
     * allocator a denominator it throws on.
     */
    Engagement::query()->create([
        'subscription_id' => $outcome->subscriptionId,
        'period_start' => $period->period_start->toDateString(),
        'instructor_id' => $dormant->id,
        'units' => 0,
    ]);

    $recognized = recognizeFirstPeriodOf($outcome->subscriptionId);

    expect($recognized->platform_minor)->toBe(30_000)
        ->and($recognized->pool_minor)->toBe(0)
        ->and(EarningAllocation::query()->count())->toBe(0);
});

it('still pays the instructors of a period that had some engagement', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_dormant_0003');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $active = Instructor::factory()->create();
    $dormant = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $active, 45);

    Engagement::query()->create([
        'subscription_id' => $outcome->subscriptionId,
        'period_start' => $period->period_start->toDateString(),
        'instructor_id' => $dormant->id,
        'units' => 0,
    ]);

    recognizeFirstPeriodOf($outcome->subscriptionId);

    /** Zero units earns nothing; it does not dilute the instructor who earned. */
    expect(EarningAllocation::query()->count())->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($active->id)->held_minor)->toBe(21_000)
        ->and(InstructorBalance::query()->find($dormant->id))->toBeNull();
});
