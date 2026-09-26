<?php

declare(strict_types=1);

use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\RefundType;
use App\Enums\SubscriptionStatus;
use App\Models\AccrualPeriod;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
 * The headline of scenario 6: **a student cancelling does not cost any
 * instructor anything**, because under accrual nobody was ever credited for
 * the months the student did not use.
 *
 * That is not a slogan here, it is an assertion — the instructor snapshots
 * before and after a mid-term refund differ only by the truncated period's own
 * recognition, and `ledger:verify` is green on both sides of it.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * An annual term bought five months ago, with engagement on every period and
 * the closed ones recognized.
 *
 * @return array{0: int, 1: Instructor, 2: Instructor}
 */
function refundableTerm(string $externalRef, int $priceMinor = 300_000): array
{
    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => $priceMinor]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        $externalRef,
        capturedAt: CarbonImmutable::now()->subMonths(5),
    );

    $alice = Instructor::factory()->create();
    $bob = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $alice, 300);
        engage($outcome->subscriptionId, $period, $bob, 100);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    return [$outcome->subscriptionId, $alice, $bob];
}

it('cancels the unused periods and costs no instructor anything', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId, $alice, $bob] = refundableTerm('ch_refund_0001');

    $before = InstructorBalance::query()->orderBy('instructor_id')->get()->keyBy('instructor_id');
    $recognizedBefore = AccrualPeriod::query()->where('subscription_id', $subscriptionId)
        ->where('status', AccrualPeriodStatus::RECOGNIZED)->count();

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_0001',
    ])->assertSuccessful();

    $periods = AccrualPeriod::query()->where('subscription_id', $subscriptionId)->orderBy('sequence')->get();

    /** Everything the student never reached is cancelled; nothing else moved. */
    expect($periods->where('status', AccrualPeriodStatus::CANCELLED)->count())->toBeGreaterThan(0)
        ->and($periods->where('status', AccrualPeriodStatus::RECOGNIZED)->count())
        ->toBeGreaterThanOrEqual($recognizedBefore)
        ->and($periods->where('status', AccrualPeriodStatus::SCHEDULED)->count())->toBe(0);

    /** The liability is exactly discharged — invariant I5, and check 5 agrees. */
    expect(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $subscriptionId))->toBe(0);

    $after = InstructorBalance::query()->orderBy('instructor_id')->get()->keyBy('instructor_id');

    /**
     * The only change to any instructor is the truncated period's recognition —
     * money *earned*, never taken. Nothing was clawed back from anybody.
     */
    foreach ([$alice, $bob] as $instructor) {
        expect($after[$instructor->id]->clawed_back_minor)->toBe(0)
            ->and($after[$instructor->id]->earned_minor)
            ->toBeGreaterThanOrEqual($before[$instructor->id]->earned_minor);
    }

    expect(LedgerEntry::query()->where('entry_type', LedgerEntryType::REFUND_CLAWBACK)->count())->toBe(0);

    $subscription = Subscription::query()->findOrFail($subscriptionId);

    expect($subscription->status)->toBe(SubscriptionStatus::REFUNDED)
        ->and($subscription->canceled_at)->not->toBeNull();
});

it('recognizes the used days of the period the refund lands inside', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId] = refundableTerm('ch_refund_0002');

    $straddled = AccrualPeriod::query()
        ->where('subscription_id', $subscriptionId)
        ->where('status', AccrualPeriodStatus::SCHEDULED)
        ->orderBy('sequence')
        ->firstOrFail();

    $originalGross = $straddled->gross_minor;
    $originalDays = $straddled->days;

    /** Mid-period, so there is a genuine split to make. */
    $effective = $straddled->period_start->addDays(10);

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_0002',
        '--effective' => $effective->toDateString(),
    ])->assertSuccessful();

    $straddled->refresh();

    expect($straddled->status)->toBe(AccrualPeriodStatus::RECOGNIZED)
        ->and($straddled->days)->toBe(10)
        ->and($straddled->days)->toBeLessThan($originalDays)
        ->and($straddled->period_end->toDateString())->toBe($effective->toDateString())
        ->and($straddled->gross_minor)->toBeLessThan($originalGross)
        /** Recognized properly: a split was computed and stored, not conjured. */
        ->and($straddled->pool_minor)->not->toBeNull()
        ->and($straddled->platform_minor)->not->toBeNull()
        ->and($straddled->pool_minor + $straddled->platform_minor)->toBe($straddled->gross_minor);

    $refund = Refund::query()->firstOrFail();

    /** The refund plus everything recognized comes back to the price. */
    $recognized = (int) AccrualPeriod::query()->where('subscription_id', $subscriptionId)
        ->where('status', AccrualPeriodStatus::RECOGNIZED)->sum('gross_minor');

    expect($refund->amount_minor + $recognized)->toBe(300_000)
        ->and($refund->type)->toBe(RefundType::PRORATA);
});

it('gives the whole price back when the refund is dated at the term start', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => 300_000]);

    /** Bought today, refunded today: nothing was delivered and nothing recognized. */
    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_refund_0003',
        capturedAt: CarbonImmutable::now(),
    );

    $this->artisan('refunds:issue', [
        'subscription' => (string) $outcome->subscriptionId,
        '--external-ref' => 're_0003',
    ])->assertSuccessful();

    expect(Refund::query()->firstOrFail()->amount_minor)->toBe(300_000)
        ->and(AccrualPeriod::query()->where('status', AccrualPeriodStatus::CANCELLED)->count())->toBe(12)
        ->and(InstructorBalance::query()->count())->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $outcome->subscriptionId))->toBe(0)
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_CASH, 0))->toBe(0);
});

it('moves no money when the term was already fully recognized', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => 30_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_refund_0004',
        capturedAt: CarbonImmutable::now()->subMonths(2),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    $earnedBefore = InstructorBalance::query()->findOrFail($instructor->id)->earned_minor;

    $this->artisan('refunds:issue', [
        'subscription' => (string) $outcome->subscriptionId,
        '--external-ref' => 're_0004',
    ])->assertSuccessful();

    /** The status moves because the fact happened; no money does. */
    expect(Refund::query()->firstOrFail()->amount_minor)->toBe(0)
        ->and(Subscription::query()->findOrFail($outcome->subscriptionId)->status)->toBe(SubscriptionStatus::REFUNDED)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->earned_minor)->toBe($earnedBefore)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::REFUND_UNEARNED)->count())->toBe(0);
});

it('records one refund however many times it is issued', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId] = refundableTerm('ch_refund_0005');

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_0005',
    ])->assertSuccessful();

    $entries = LedgerEntry::query()->count();
    $cancelled = AccrualPeriod::query()->where('status', AccrualPeriodStatus::CANCELLED)->count();

    /** Same ref, and a different one: both are no-ops. */
    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_0005',
    ])->expectsOutputToContain('already refunded')->assertSuccessful();

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_0005_again',
    ])->expectsOutputToContain('already refunded')->assertSuccessful();

    expect(Refund::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe($entries)
        ->and(AccrualPeriod::query()->where('status', AccrualPeriodStatus::CANCELLED)->count())->toBe($cancelled);
});
