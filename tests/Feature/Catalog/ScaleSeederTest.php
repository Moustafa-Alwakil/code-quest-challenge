<?php

declare(strict_types=1);

use App\Models\AccrualPeriod;
use App\Models\Engagement;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ScaleSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The scale seeder writes in bulk, which means it bypasses the per-term
 * transaction that normally guarantees a term, its payment, its postings and
 * its schedule arrive together.
 *
 * That trade is deliberate — fifty thousand transactions is a seeder nobody
 * waits for — but it moves the burden of proof here. These tests run it small
 * and check the things the bulk path could plausibly get wrong: a payment
 * keyed to the wrong term, a posting keyed to the wrong payment, a schedule
 * whose gross no longer sums to the price.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00:00'));

    config([
        'scale.subscriptions' => 40,
        'scale.instructors' => 6,
        'scale.students' => 10,
        'scale.chunk' => 7,
    ]);

    $this->seed(ScaleSeeder::class);
});

afterEach(function (): void {
    assertLedgerBalanced();
});

it('writes every term with its payment, schedule and posting', function (): void {
    expect(Subscription::query()->count())->toBe(40)
        ->and(Payment::query()->count())->toBe(40)
        ->and(Instructor::query()->count())->toBe(6)
        ->and(User::query()->where('is_admin', false)->count())->toBe(10)
        ->and(AccrualPeriod::query()->count())->toBeGreaterThan(40)
        ->and(Engagement::query()->count())->toBeGreaterThan(0)
        /** Two legs per term, and not one more. */
        ->and(LedgerEntry::query()->count())->toBe(80);
});

it('gives each payment to the term it belongs to', function (): void {
    /**
     * The bulk path derives subscription ids from a run of consecutive
     * autoincrements rather than reading them back. An off-by-one there would
     * attach every payment to the wrong term — and the totals would still look
     * right, which is exactly why this is checked by identity rather than by
     * count.
     */
    $mismatched = Payment::query()
        ->join('subscriptions', 'subscriptions.id', '=', 'payments.subscription_id')
        ->where(function ($query): void {
            $query->whereColumn('payments.amount_minor', '!=', 'subscriptions.price_minor')
                /** `term_start` is the payment's capture *date* (F04), so compare dates. */
                ->orWhereRaw('date(payments.captured_at) <> subscriptions.term_start');
        })
        ->count();

    expect($mismatched)->toBe(0);

    /** Every term has exactly one, and every payment has a term. */
    expect(Subscription::query()->whereDoesntHave('payment')->count())->toBe(0);
});

it('keys each posting to the payment that caused it', function (): void {
    $paymentIds = Payment::query()->pluck('id');

    $orphaned = LedgerEntry::query()
        ->where('reference_type', 'payment')
        ->whereNotIn('reference_id', $paymentIds)
        ->count();

    expect($orphaned)->toBe(0);

    /**
     * And the liability is keyed to the *term*, not the payment (R5), so a
     * deferred-revenue entry naming a subscription that does not exist is the
     * same off-by-one seen from the other side.
     */
    $misKeyed = LedgerEntry::query()
        ->where('account_type', 'deferred_revenue')
        ->whereNotIn('account_id', Subscription::query()->pluck('id'))
        ->count();

    expect($misKeyed)->toBe(0);
});

it('splits every price across its periods exactly', function (): void {
    /** Invariant I8, over every term the bulk path wrote. */
    $mismatched = DB::table('accrual_periods')
        ->selectRaw('subscription_id, sum(gross_minor) as total')
        ->groupBy('subscription_id')
        ->havingRaw('total <> (select price_minor from subscriptions where subscriptions.id = subscription_id)')
        ->get();

    expect($mismatched->all())->toBe([]);
});

it('owes each term exactly the time it has not delivered', function (): void {
    /**
     * Check 5's statement, which `assertLedgerBalanced()` already runs — spelled
     * out here because for a freshly seeded term it has a simple form: nothing
     * is recognized, so the liability is the whole price.
     */
    $subscription = Subscription::query()->firstOrFail();

    $owed = -LedgerEntry::query()
        ->where('account_type', 'deferred_revenue')
        ->where('account_id', $subscription->id)
        ->sum('amount_minor');

    expect($owed)->toBe($subscription->price_minor);
});

it('only ever engages instructors that exist, on periods that exist', function (): void {
    $orphanInstructors = Engagement::query()
        ->whereNotIn('instructor_id', Instructor::query()->pluck('id'))
        ->count();

    $orphanPeriods = Engagement::query()
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')
                ->from('accrual_periods')
                ->whereColumn('accrual_periods.subscription_id', 'subscription_period_engagement.subscription_id')
                ->whereColumn('accrual_periods.period_start', 'subscription_period_engagement.period_start');
        })
        ->count();

    expect($orphanInstructors)->toBe(0)
        ->and($orphanPeriods)->toBe(0);
});

it('leaves some periods dormant, so D-3 exists at scale too', function (): void {
    $periods = AccrualPeriod::query()->count();

    $engaged = Engagement::query()
        ->distinct()
        ->count(DB::raw('concat(subscription_id, ":", period_start)'));

    expect($engaged)->toBeLessThan($periods)
        ->and($engaged)->toBeGreaterThan(0);
});

it('refuses to run on top of terms that already exist', function (): void {
    /**
     * The bulk path cannot be layered: its ids come from a run of consecutive
     * autoincrements, and a second run would collide on `external_ref`
     * thousands of rows later, somewhere nobody could place.
     */
    expect(fn () => $this->seed(ScaleSeeder::class))
        ->toThrow(RuntimeException::class, 'needs an empty subscriptions table');
});
