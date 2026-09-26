<?php

declare(strict_types=1);

use App\Actions\Subscriptions\SubscribeStudentAction;
use App\DTOs\Subscriptions\SubscribeStudentData;
use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\PaymentMismatchException;
use App\Models\AccrualPeriod;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Subscriptions\SubscriptionOutcome;
use Carbon\CarbonImmutable;

/*
 * Recording a captured payment is the single entry point into the money core
 * (F04). Everything downstream — recognition, payouts, refunds — reads rows this
 * action wrote, so its two guarantees are load-bearing:
 *
 *   1. one payment produces one subscription, one posting and one schedule,
 *      however many times it is recorded;
 *   2. a payment that is not the plan's price produces *nothing*.
 *
 * `assertLedgerBalanced()` runs after every test here, so each one also proves
 * invariants I1-I4 on top of what it was written for.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * The capture moment used throughout: 23:00 on a month end, in a leap year.
 *
 * Both halves matter — the time proves a purchase late in the day still gets
 * whole days, and Jan 31 is the date a chained schedule gets wrong (R10).
 */
function capturedAt(): CarbonImmutable
{
    return CarbonImmutable::parse('2024-01-31 23:00:00');
}

function recordCapturedPayment(
    User $user,
    Plan $plan,
    string $externalRef = 'ch_live_0001',
    ?int $amountMinor = null,
    ?string $currency = null,
    ?CarbonImmutable $capturedAt = null,
): SubscriptionOutcome {
    return app(SubscribeStudentAction::class)(SubscribeStudentData::forCapturedPayment(
        userId: $user->id,
        planId: $plan->id,
        externalRef: $externalRef,
        amountMinor: $amountMinor ?? $plan->price_minor,
        currency: $currency ?? $plan->currency,
        capturedAt: $capturedAt ?? capturedAt(),
    ));
}

function ledgerSumFor(LedgerAccountType $accountType, int $accountId): int
{
    return (int) LedgerEntry::query()
        ->where('account_type', $accountType)
        ->where('account_id', $accountId)
        ->sum('amount_minor');
}

it('records the term, the payment, the liability and the schedule', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $outcome = recordCapturedPayment($user, $plan);

    $subscription = Subscription::query()->findOrFail($outcome->subscriptionId);
    $payment = Payment::query()->where('subscription_id', $subscription->id)->firstOrFail();

    expect($outcome->replayed)->toBeFalse()
        ->and($subscription->status)->toBe(SubscriptionStatus::ACTIVE)
        /** The term starts when the gateway took the money, as a whole UTC day. */
        ->and($subscription->term_start->toDateString())->toBe('2024-01-31')
        ->and($subscription->term_end->toDateString())->toBe('2025-01-31')
        ->and($subscription->term_days)->toBe(366)
        ->and($subscription->price_minor)->toBe(300_000)
        ->and($subscription->currency)->toBe('EGP')
        ->and($subscription->canceled_at)->toBeNull()
        ->and($payment->external_ref)->toBe('ch_live_0001')
        ->and($payment->amount_minor)->toBe(300_000)
        ->and($payment->captured_at->toDateTimeString())->toBe('2024-01-31 23:00:00');
});

it('posts payment_received as cash in and an equal liability for this subscription', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $outcome = recordCapturedPayment($user, $plan);
    $payment = Payment::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();

    $entries = LedgerEntry::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and(LedgerEntry::query()->distinct()->count('transaction_uuid'))->toBe(1)
        ->and($entries->pluck('entry_type')->unique()->all())->toBe([LedgerEntryType::PAYMENT_RECEIVED])
        /** Keyed by the payment, so recording that fact twice can only ever write it once. */
        ->and($entries->pluck('reference_type')->unique()->all())->toBe(['payment'])
        ->and($entries->pluck('reference_id')->unique()->all())->toBe([$payment->id])
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_CASH, 0))->toBe(300_000)
        /** Credit-normal: the liability is the negated sum, so the platform owes this term EGP 3 000. */
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $outcome->subscriptionId))->toBe(-300_000)
        /** No instructor has earned anything yet, so no snapshot row was created (R18). */
        ->and(InstructorBalance::query()->count())->toBe(0);
});

it('writes twelve scheduled periods that sum to the price, anchored not chained', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $outcome = recordCapturedPayment($user, $plan);

    $periods = AccrualPeriod::query()
        ->where('subscription_id', $outcome->subscriptionId)
        ->orderBy('sequence')
        ->get();

    expect($periods)->toHaveCount(12)
        ->and($periods->pluck('sequence')->all())->toBe(range(1, 12))
        ->and($periods->pluck('status')->unique()->all())->toBe([AccrualPeriodStatus::SCHEDULED])
        ->and((int) $periods->sum('gross_minor'))->toBe(300_000)
        ->and($periods->pluck('period_start')->map->toDateString()->all())->toBe([
            '2024-01-31',
            '2024-02-29',
            '2024-03-31',
            '2024-04-30',
            '2024-05-31',
            '2024-06-30',
            '2024-07-31',
            '2024-08-31',
            '2024-09-30',
            '2024-10-31',
            '2024-11-30',
            '2024-12-31',
        ])
        ->and($periods->last()->period_end->toDateString())->toBe('2025-01-31')
        /** Half-open: each period ends where the next begins, so no day is earned twice. */
        ->and($periods->pluck('period_end')->map->toDateString()->slice(0, 11)->values()->all())
        ->toBe($periods->pluck('period_start')->map->toDateString()->slice(1, 11)->values()->all())
        ->and((int) $periods->sum('days'))->toBe(366)
        /** F05's columns exist but stay null until recognition computes a split. */
        ->and($periods->pluck('pool_minor')->unique()->all())->toBe([null])
        ->and($periods->pluck('platform_minor')->unique()->all())->toBe([null])
        ->and($periods->pluck('recognized_at')->unique()->all())->toBe([null]);
});

it('replays the same external_ref into one of everything', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $first = recordCapturedPayment($user, $plan);
    $second = recordCapturedPayment($user, $plan);

    expect($first->replayed)->toBeFalse()
        ->and($second->replayed)->toBeTrue()
        ->and($second->subscriptionId)->toBe($first->subscriptionId)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(LedgerEntry::query()->distinct()->count('transaction_uuid'))->toBe(1)
        ->and(AccrualPeriod::query()->count())->toBe(12)
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $first->subscriptionId))->toBe(-300_000);
});

it('records a second, different payment for the same student as its own subscription', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $first = recordCapturedPayment($user, $plan, externalRef: 'ch_live_0001');
    $second = recordCapturedPayment($user, $plan, externalRef: 'ch_live_0002');

    expect($second->subscriptionId)->not->toBe($first->subscriptionId)
        ->and(Subscription::query()->count())->toBe(2)
        ->and(AccrualPeriod::query()->count())->toBe(24)
        ->and(LedgerEntry::query()->count())->toBe(4)
        /** Keyed per subscription (R5), so the two liabilities never merge. */
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $first->subscriptionId))->toBe(-300_000)
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $second->subscriptionId))->toBe(-300_000)
        ->and(ledgerSumFor(LedgerAccountType::PLATFORM_CASH, 0))->toBe(600_000);
});

it('rejects an amount that is not the plan price and writes nothing at all', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    expect(fn () => recordCapturedPayment($user, $plan, amountMinor: 299_999))
        ->toThrow(PaymentMismatchException::class);

    expect(Subscription::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(AccrualPeriod::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('rejects the right number in the wrong currency', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    expect(fn () => recordCapturedPayment($user, $plan, currency: 'USD'))
        ->toThrow(PaymentMismatchException::class, 'priced in EGP');

    expect(Subscription::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('gives a monthly plan a single period worth the whole price', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->monthly()->create();

    $outcome = recordCapturedPayment($user, $plan);

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->sole();

    expect(AccrualPeriod::query()->count())->toBe(1)
        ->and($period->sequence)->toBe(1)
        ->and($period->gross_minor)->toBe(30_000)
        ->and($period->period_start->toDateString())->toBe('2024-01-31')
        ->and($period->period_end->toDateString())->toBe('2024-02-29')
        ->and($period->days)->toBe(29)
        ->and(Subscription::query()->findOrFail($outcome->subscriptionId)->term_days)->toBe(29);
});

it('splits a quarterly price unevenly but exactly', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->quarterly()->create();

    $outcome = recordCapturedPayment($user, $plan, capturedAt: CarbonImmutable::parse('2023-01-01'));

    $periods = AccrualPeriod::query()
        ->where('subscription_id', $outcome->subscriptionId)
        ->orderBy('sequence')
        ->get();

    expect($periods->pluck('gross_minor')->all())->toBe([27_556, 24_889, 27_555])
        ->and((int) $periods->sum('gross_minor'))->toBe(80_000);
});

it('starts the term at capture even when the fact is recorded weeks later', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $this->travelTo(CarbonImmutable::parse('2024-03-15 10:00:00'));

    $outcome = recordCapturedPayment($user, $plan);

    $subscription = Subscription::query()->findOrFail($outcome->subscriptionId);
    $payment = Payment::query()->where('subscription_id', $subscription->id)->firstOrFail();

    expect($subscription->term_start->toDateString())->toBe('2024-01-31')
        ->and($subscription->term_end->toDateString())->toBe('2025-01-31')
        /** created_at is when we learned of it; captured_at is when the money moved. */
        ->and($payment->created_at->toDateString())->toBe('2024-03-15')
        ->and($payment->captured_at->toDateString())->toBe('2024-01-31');
});

it('is unaffected by a plan price change after the purchase', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $existing = recordCapturedPayment($user, $plan, externalRef: 'ch_live_0001');

    $plan->update(['price_minor' => 400_000]);

    /** The old price is no longer a valid payment for this plan... */
    expect(fn () => recordCapturedPayment($user, $plan->fresh(), externalRef: 'ch_live_0002', amountMinor: 300_000))
        ->toThrow(PaymentMismatchException::class);

    /** ...and the subscription bought at the old price is untouched. */
    $subscription = Subscription::query()->findOrFail($existing->subscriptionId);

    expect($subscription->price_minor)->toBe(300_000)
        ->and((int) AccrualPeriod::query()->where('subscription_id', $subscription->id)->sum('gross_minor'))->toBe(300_000)
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $subscription->id))->toBe(-300_000);

    /** A purchase at the new price stands on its own. */
    $repriced = recordCapturedPayment($user, $plan->fresh(), externalRef: 'ch_live_0003', amountMinor: 400_000);

    expect(Subscription::query()->findOrFail($repriced->subscriptionId)->price_minor)->toBe(400_000)
        ->and((int) AccrualPeriod::query()->where('subscription_id', $repriced->subscriptionId)->sum('gross_minor'))->toBe(400_000);
});

it('leaves ledger:verify green', function (): void {
    $user = User::factory()->create();

    recordCapturedPayment($user, Plan::factory()->annual()->create(), externalRef: 'ch_live_0001');
    recordCapturedPayment($user, Plan::factory()->monthly()->create(), externalRef: 'ch_live_0002');

    $this->artisan('ledger:verify')->assertExitCode(0);
});
