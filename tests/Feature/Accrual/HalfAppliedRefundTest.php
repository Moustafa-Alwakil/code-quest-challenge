<?php

declare(strict_types=1);

use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * The two states a refund passes through, applied by hand and only halfway:
 * a period cancelled without the `refund_unearned` posting that discharges its
 * liability, and an allocation clawed back without the posting that reverses
 * it. F09 does each pair in one transaction, so neither state below can occur
 * in production.
 *
 * What is under test is that F05 *skips* them — the compare-and-set refuses
 * anything that is not `scheduled`, and the maturation sweep refuses anything
 * clawed back.
 *
 * `assertLedgerBalanced()` is deliberately not registered here: with only half
 * of each pair applied, `ledger:verify` reports a disagreement, and that is the
 * correct behaviour rather than something this file should work around.
 */

it('refuses to recognize a period a refund has cancelled', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->monthly()->create(['price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_skip_0001');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $alice = Instructor::factory()->create();

    Engagement::query()->create([
        'subscription_id' => $outcome->subscriptionId,
        'period_start' => $period->period_start->toDateString(),
        'instructor_id' => $alice->id,
        'units' => 60,
    ]);

    AccrualPeriod::query()->whereKey($period->id)->update(['status' => AccrualPeriodStatus::CANCELLED]);

    recognizeFirstPeriodOf($outcome->subscriptionId);

    /** Nobody earned anything, and the status the refund set still stands. */
    expect(EarningAllocation::query()->count())->toBe(0)
        ->and(InstructorBalance::query()->count())->toBe(0)
        ->and($period->refresh()->status)->toBe(AccrualPeriodStatus::CANCELLED)
        ->and($period->pool_minor)->toBeNull()
        ->and($period->platform_minor)->toBeNull()
        /**
         * The liability is untouched: only the refund posting may discharge it.
         * Credit-normal, so the raw signed sum is negative and the *owed*
         * amount is its negation — the whole 30 000 is still outstanding.
         */
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $outcome->subscriptionId))->toBe(-30_000);
});

it('leaves a clawed-back allocation held by nobody', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => 30_000]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, 'ch_clawback_0001');

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $instructor = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $instructor, 60);

    $period = recognizeFirstPeriodOf($outcome->subscriptionId);

    /**
     * What a full refund inside the hold window leaves behind (D-6, F09): the
     * allocation is reversed before it ever matured, so the sweep must not
     * later hand the instructor money that was taken back.
     */
    EarningAllocation::query()->update(['clawed_back_at' => CarbonImmutable::now()]);

    expect(releaseMaturedAt($period->period_end->addDays(7)))->toBe(0)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->available_minor)->toBe(0);
});
