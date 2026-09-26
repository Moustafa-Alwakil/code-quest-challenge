<?php

declare(strict_types=1);

use App\Actions\Subscriptions\SubscribeStudentAction;
use App\DTOs\Subscriptions\SubscribeStudentData;
use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Exceptions\PaymentMismatchException;
use App\Models\AccrualPeriod;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccrualService;
use App\Support\Accrual\AccrualSchedule;
use App\Support\Money;
use App\Support\Subscriptions\SubscriptionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
 * Independent verification of F04's idempotency and failure paths (F04 review).
 *
 * `SubscribeStudentTest` proves the row *counts* match after a replay. That is
 * necessary and not sufficient: counts would also match if the second call
 * rewrote the same rows, bumped `updated_at`, or re-posted and had the ledger's
 * own unique index swallow it. The spec's words are stronger — "a replay writes
 * nothing" — so this file watches the statements the second call issues.
 *
 * The mismatch tests here assert emptiness across *every* table F04 writes,
 * including the two `SubscribeStudentTest` leaves out of its currency case.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

function subscribeTo(Plan $plan, User $user, string $externalRef, ?int $amountMinor = null, ?string $currency = null, ?CarbonImmutable $capturedAt = null): SubscriptionOutcome
{
    return app(SubscribeStudentAction::class)(SubscribeStudentData::forCapturedPayment(
        userId: $user->id,
        planId: $plan->id,
        externalRef: $externalRef,
        amountMinor: $amountMinor ?? $plan->price_minor,
        currency: $currency ?? $plan->currency,
        capturedAt: $capturedAt ?? CarbonImmutable::parse('2024-01-31 23:00:00'),
    ));
}

/**
 * Every statement the callback issues that changes data.
 *
 * @return list<string>
 */
function writeStatementsDuring(Closure $callback): array
{
    $writes = [];

    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    try {
        $callback();
    } finally {
        DB::getEventDispatcher()->forget(Illuminate\Database\Events\QueryExecuted::class);
    }

    return $writes;
}

/**
 * @return array<string, int>
 */
function rowCounts(): array
{
    return [
        'subscriptions' => Subscription::query()->count(),
        'payments' => Payment::query()->count(),
        'accrual_periods' => AccrualPeriod::query()->count(),
        'ledger_entries' => LedgerEntry::query()->count(),
        'instructor_balances' => InstructorBalance::query()->count(),
    ];
}

it('issues no write statement at all when the same external_ref is recorded again', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $first = subscribeTo($plan, $user, 'ch_replay_0001');

    $before = [
        'counts' => rowCounts(),
        'subscription' => Subscription::query()->findOrFail($first->subscriptionId)->only(['status', 'term_start', 'term_end', 'term_days', 'price_minor', 'updated_at']),
        'payment' => Payment::query()->where('subscription_id', $first->subscriptionId)->firstOrFail()->only(['id', 'amount_minor', 'captured_at', 'updated_at']),
        'maxLedgerId' => (int) LedgerEntry::query()->max('id'),
        'maxPeriodId' => (int) AccrualPeriod::query()->max('id'),
        'periodUpdatedAt' => AccrualPeriod::query()->orderBy('sequence')->pluck('updated_at')->map->toDateTimeString()->all(),
    ];

    /** A second after the first call, so any rewritten timestamp would show. */
    $this->travelTo(CarbonImmutable::now()->addMinute());

    $writes = [];
    $second = null;

    $writes = writeStatementsDuring(function () use (&$second, $plan, $user): void {
        $second = subscribeTo($plan, $user, 'ch_replay_0001');
    });

    expect($writes)->toBe([], 'A replay must write nothing: it issued '.count($writes).' write statement(s).')
        ->and($second->replayed)->toBeTrue()
        ->and($second->subscriptionId)->toBe($first->subscriptionId)
        ->and(rowCounts())->toBe($before['counts'])
        ->and(Subscription::query()->findOrFail($first->subscriptionId)->only(['status', 'term_start', 'term_end', 'term_days', 'price_minor', 'updated_at']))
        ->toEqual($before['subscription'])
        ->and(Payment::query()->where('subscription_id', $first->subscriptionId)->firstOrFail()->only(['id', 'amount_minor', 'captured_at', 'updated_at']))
        ->toEqual($before['payment'])
        /** No new ledger leg and no new period, not even one the unique index swallowed. */
        ->and((int) LedgerEntry::query()->max('id'))->toBe($before['maxLedgerId'])
        ->and((int) AccrualPeriod::query()->max('id'))->toBe($before['maxPeriodId'])
        ->and(AccrualPeriod::query()->orderBy('sequence')->pluck('updated_at')->map->toDateTimeString()->all())
        ->toBe($before['periodUpdatedAt']);
});

it('writes nothing anywhere when the captured amount is not the plan price', function (int $amountMinor): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    expect(fn () => subscribeTo($plan, $user, 'ch_wrong_amount', amountMinor: $amountMinor))
        ->toThrow(PaymentMismatchException::class);

    expect(rowCounts())->toBe([
        'subscriptions' => 0,
        'payments' => 0,
        'accrual_periods' => 0,
        'ledger_entries' => 0,
        'instructor_balances' => 0,
    ]);
})->with([
    'one piastre short' => [299_999],
    'one piastre over' => [300_001],
    'a different plan\'s price' => [30_000],
]);

it('writes nothing anywhere when the currency is not the plan currency', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    expect(fn () => subscribeTo($plan, $user, 'ch_wrong_currency', currency: 'USD'))
        ->toThrow(PaymentMismatchException::class, 'priced in EGP');

    expect(rowCounts())->toBe([
        'subscriptions' => 0,
        'payments' => 0,
        'accrual_periods' => 0,
        'ledger_entries' => 0,
        'instructor_balances' => 0,
    ]);
});

it('writes nothing when the plan does not exist', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();
    $missingPlanId = $plan->id + 999;

    expect(fn () => app(SubscribeStudentAction::class)(SubscribeStudentData::forCapturedPayment(
        userId: $user->id,
        planId: $missingPlanId,
        externalRef: 'ch_missing_plan',
        amountMinor: 300_000,
        currency: 'EGP',
        capturedAt: CarbonImmutable::parse('2024-01-31'),
    )))->toThrow(ModelNotFoundException::class);

    expect(rowCounts())->toBe([
        'subscriptions' => 0,
        'payments' => 0,
        'accrual_periods' => 0,
        'ledger_entries' => 0,
        'instructor_balances' => 0,
    ]);
});

it('writes nothing when a plan\'s term is longer than the scheduler supports', function (): void {
    $user = User::factory()->create();

    /** `accrual_periods.sequence` is a TINYINT with 1…12 in mind; a two-year plan is not a term this system can schedule. */
    $plan = Plan::factory()->create(['interval_months' => 24, 'price_minor' => 500_000, 'currency' => 'EGP']);

    expect(fn () => subscribeTo($plan, $user, 'ch_two_year'))
        ->toThrow(App\Exceptions\AccrualScheduleException::class, 'A term runs for 1 to 12 months');

    expect(rowCounts())->toBe([
        'subscriptions' => 0,
        'payments' => 0,
        'accrual_periods' => 0,
        'ledger_entries' => 0,
        'instructor_balances' => 0,
    ]);
});

it('writes a twelve-period schedule in a single insert statement', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    /*
     * `scheduleFor()` inserts one multi-row statement rather than looping over
     * chunks (`.ai/rules/services.md`: a list bounded by a constant gets no
     * chunk loop). What matters for the money is that all twelve rows land, and
     * that they land together: a partial insert inside the action's transaction
     * would roll the payment back with it, but a *silent* one would strand
     * deferred revenue no recognition run could release.
     */
    $inserts = [];

    DB::listen(function ($query) use (&$inserts): void {
        if (str_contains($query->sql, 'insert') && str_contains($query->sql, 'accrual_periods')) {
            $inserts[] = $query->sql;
        }
    });

    try {
        subscribeTo($plan, $user, 'ch_one_insert');
    } finally {
        DB::getEventDispatcher()->forget(Illuminate\Database\Events\QueryExecuted::class);
    }

    expect($inserts)->toHaveCount(1, 'The schedule must be one statement, not a chunk loop.')
        /** Twelve value tuples in that one statement. */
        ->and(mb_substr_count($inserts[0], '(?, ?, ?, ?, ?, ?, ?)'))->toBe(12)
        ->and(AccrualPeriod::query()->count())->toBe(12)
        ->and((int) AccrualPeriod::query()->sum('gross_minor'))->toBe(300_000)
        ->and(AccrualPeriod::query()->pluck('sequence')->all())->toBe(range(1, 12));
});

it('writes no period and issues no insert when the same schedule is applied twice', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $outcome = subscribeTo($plan, $user, 'ch_reschedule_0001');

    $schedule = AccrualSchedule::forTerm(
        CarbonImmutable::parse('2024-01-31 23:00:00'),
        12,
        Money::of(300_000, 'EGP'),
    );

    $before = AccrualPeriod::query()->orderBy('sequence')->get(['id', 'sequence', 'period_start', 'days', 'gross_minor', 'status'])->toArray();

    $written = app(AccrualService::class)->scheduleFor($outcome->subscriptionId, $schedule);

    expect($written)->toBe(0, 'Re-running the scheduler must be a no-op.')
        ->and(AccrualPeriod::query()->count())->toBe(12)
        ->and(AccrualPeriod::query()->orderBy('sequence')->get(['id', 'sequence', 'period_start', 'days', 'gross_minor', 'status'])->toArray())
        ->toEqual($before)
        ->and((int) AccrualPeriod::query()->sum('gross_minor'))->toBe(300_000);
});

it('refuses to duplicate a schedule even when the periods are re-derived at different amounts', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $outcome = subscribeTo($plan, $user, 'ch_reschedule_0002');

    /** Same subscription, same boundaries, a different price: both unique keys still hold. */
    $repriced = AccrualSchedule::forTerm(
        CarbonImmutable::parse('2024-01-31 23:00:00'),
        12,
        Money::of(400_000, 'EGP'),
    );

    expect(app(AccrualService::class)->scheduleFor($outcome->subscriptionId, $repriced))->toBe(0)
        ->and((int) AccrualPeriod::query()->sum('gross_minor'))->toBe(300_000)
        ->and(AccrualPeriod::query()->count())->toBe(12);
});

it('keeps deferred revenue equal to the price and platform cash equal to their sum', function (): void {
    $user = User::factory()->create();

    $annual = subscribeTo(Plan::factory()->annual()->create(), $user, 'ch_sum_0001');
    $quarterly = subscribeTo(Plan::factory()->quarterly()->create(), $user, 'ch_sum_0002', capturedAt: CarbonImmutable::parse('2023-01-01'));
    $monthly = subscribeTo(Plan::factory()->monthly()->create(), $user, 'ch_sum_0003', capturedAt: CarbonImmutable::parse('2024-02-29'));

    $deferredFor = fn (int $subscriptionId): int => (int) LedgerEntry::query()
        ->where('account_type', LedgerAccountType::DEFERRED_REVENUE)
        ->where('account_id', $subscriptionId)
        ->sum('amount_minor');

    foreach ([$annual, $quarterly, $monthly] as $outcome) {
        $subscription = Subscription::query()->findOrFail($outcome->subscriptionId);

        expect($deferredFor($subscription->id))->toBe(-$subscription->price_minor)
            /** Invariant I8, per subscription. */
            ->and((int) AccrualPeriod::query()->where('subscription_id', $subscription->id)->sum('gross_minor'))
            ->toBe($subscription->price_minor)
            ->and((int) AccrualPeriod::query()->where('subscription_id', $subscription->id)->sum('days'))
            ->toBe($subscription->term_days)
            ->and(AccrualPeriod::query()->where('subscription_id', $subscription->id)->pluck('status')->unique()->all())
            ->toBe([AccrualPeriodStatus::SCHEDULED]);
    }

    expect((int) LedgerEntry::query()->where('account_type', LedgerAccountType::PLATFORM_CASH)->where('account_id', 0)->sum('amount_minor'))
        ->toBe(410_000)
        ->and((int) Subscription::query()->sum('price_minor'))->toBe(410_000)
        /** F04 creates no instructor snapshot: nobody has earned anything yet (R18, R20). */
        ->and(InstructorBalance::query()->count())->toBe(0);
});

it('records a Cairo capture on the Cairo date, with every stored day count matching its own boundaries', function (): void {
    /*
     * R26 at the row level. The capture is 13:45 in Africa/Cairo on a date whose
     * fourth monthly boundary (2023-04-28) is the night Cairo's DST shift removes
     * local midnight — the operand pair that used to truncate `days` to 29.
     *
     * Three things are asserted about the stored rows, not about the Support
     * class: `term_start` is still the Cairo calendar date and still equals
     * `captured_at`'s date (which `->utc()` would have broken), every `days` is
     * the calendar distance between its own two boundaries, and Σ days is
     * `term_days`.
     */
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $outcome = subscribeTo(
        $plan,
        $user,
        'ch_cairo_0001',
        capturedAt: CarbonImmutable::parse('2023-01-28 13:45:00', 'Africa/Cairo'),
    );

    $subscription = Subscription::query()->findOrFail($outcome->subscriptionId);
    $payment = Payment::query()->where('subscription_id', $subscription->id)->firstOrFail();
    $periods = AccrualPeriod::query()->where('subscription_id', $subscription->id)->orderBy('sequence')->get();

    $calendarDays = static fn (string $from, string $to): int => (int) (new DateTimeImmutable($from, new DateTimeZone('UTC')))
        ->diff(new DateTimeImmutable($to, new DateTimeZone('UTC')))->days;

    foreach ($periods as $period) {
        expect($period->days)->toBe(
            $calendarDays($period->period_start->toDateString(), $period->period_end->toDateString()),
            "Period {$period->sequence} stores {$period->days} days between {$period->period_start->toDateString()} and {$period->period_end->toDateString()}.",
        );
    }

    expect($subscription->term_start->toDateString())->toBe('2023-01-28')
        ->and($payment->captured_at->toDateString())->toBe('2023-01-28')
        ->and($subscription->term_end->toDateString())->toBe('2024-01-28')
        ->and($subscription->term_days)->toBe(365)
        ->and((int) $periods->sum('days'))->toBe($subscription->term_days)
        ->and($periods->firstWhere('sequence', 4)->days)->toBe(30)
        ->and((int) $periods->sum('gross_minor'))->toBe(300_000);
});
