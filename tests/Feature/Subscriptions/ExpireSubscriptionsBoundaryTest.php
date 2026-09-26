<?php

declare(strict_types=1);

use App\Actions\Subscriptions\ExpireSubscriptionsAction;
use App\Actions\Subscriptions\SubscribeStudentAction;
use App\DTOs\Subscriptions\ExpireSubscriptionsData;
use App\DTOs\Subscriptions\SubscribeStudentData;
use App\Enums\AccrualPeriodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AccrualPeriod;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Independent verification of the expiry sweep (F04 review).
 *
 * `ExpireSubscriptionsTest` proves the label moves and that a second run expires
 * nothing. Three things it leaves open, and this file closes:
 *
 *  - the chunk loop terminates when the row count is an exact multiple of the
 *    chunk size — the shape that loops forever if the guard is written `>=`;
 *  - the bounded `UPDATE` really is MySQL's `UPDATE … ORDER BY … LIMIT` rather
 *    than an unbounded statement that happens to pass;
 *  - the sweep is inert for money: it touches no accrual period and posts no
 *    ledger entry, and a term it has expired still replays and still sums.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 08:00:00'));
});

/**
 * @return list<string>
 */
function updateStatementsDuring(Closure $callback): array
{
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (preg_match('/^\s*update\b/i', $query->sql) === 1) {
            $statements[] = $query->sql;
        }
    });

    try {
        $callback();
    } finally {
        DB::getEventDispatcher()->forget(Illuminate\Database\Events\QueryExecuted::class);
    }

    return $statements;
}

it('terminates when the ended terms are an exact multiple of the chunk size', function (): void {
    Subscription::factory()->count(4)->endedDaysAgo(2)->create();

    $statements = [];
    $expired = 0;

    $statements = updateStatementsDuring(function () use (&$expired): void {
        $expired = app(ExpireSubscriptionsAction::class)(ExpireSubscriptionsData::fromCommand(null, '2'));
    });

    /** 2 + 2 + 0: the empty batch is what stops the loop, and it must happen. */
    expect($expired)->toBe(4)
        ->and($statements)->toHaveCount(3)
        ->and(Subscription::query()->where('status', SubscriptionStatus::EXPIRED)->count())->toBe(4);
});

it('bounds each batch with MySQL ORDER BY and LIMIT', function (): void {
    Subscription::factory()->count(3)->endedDaysAgo(2)->create();

    $statements = updateStatementsDuring(function (): void {
        app(ExpireSubscriptionsAction::class)(ExpireSubscriptionsData::fromCommand(null, '2'));
    });

    expect($statements)->not->toBe([])
        ->and($statements[0])->toContain('order by')
        ->and($statements[0])->toContain('limit 2')
        ->and($statements[0])->toContain('update `subscriptions`');
});

it('leaves the schedule, the payment and the ledger untouched', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $outcome = app(SubscribeStudentAction::class)(SubscribeStudentData::forCapturedPayment(
        userId: $user->id,
        planId: $plan->id,
        externalRef: 'ch_expiry_0001',
        amountMinor: 300_000,
        currency: 'EGP',
        /** A term that ended before the frozen clock. */
        capturedAt: CarbonImmutable::parse('2023-01-31 09:00:00'),
    ));

    $periodsBefore = AccrualPeriod::query()->orderBy('sequence')->get(['id', 'sequence', 'days', 'gross_minor', 'status', 'pool_minor', 'platform_minor'])->toArray();
    $ledgerBefore = LedgerEntry::query()->orderBy('id')->get(['id', 'account_type', 'account_id', 'amount_minor'])->toArray();

    expect(app(ExpireSubscriptionsAction::class)(ExpireSubscriptionsData::fromCommand()))->toBe(1);

    $subscription = Subscription::query()->findOrFail($outcome->subscriptionId);

    expect($subscription->status)->toBe(SubscriptionStatus::EXPIRED)
        /** Cosmetic: not a cancellation. */
        ->and($subscription->canceled_at)->toBeNull()
        ->and($subscription->price_minor)->toBe(300_000)
        ->and(AccrualPeriod::query()->orderBy('sequence')->get(['id', 'sequence', 'days', 'gross_minor', 'status', 'pool_minor', 'platform_minor'])->toArray())
        ->toEqual($periodsBefore)
        ->and(LedgerEntry::query()->orderBy('id')->get(['id', 'account_type', 'account_id', 'amount_minor'])->toArray())
        ->toEqual($ledgerBefore)
        ->and(AccrualPeriod::query()->pluck('status')->unique()->all())->toBe([AccrualPeriodStatus::SCHEDULED])
        ->and((int) AccrualPeriod::query()->sum('gross_minor'))->toBe(300_000);

    /** The money path does not read `status`: an expired term still replays. */
    $replay = app(SubscribeStudentAction::class)(SubscribeStudentData::forCapturedPayment(
        userId: $user->id,
        planId: $plan->id,
        externalRef: 'ch_expiry_0001',
        amountMinor: 300_000,
        currency: 'EGP',
        capturedAt: CarbonImmutable::parse('2023-01-31 09:00:00'),
    ));

    expect($replay->replayed)->toBeTrue()
        ->and($replay->subscriptionId)->toBe($outcome->subscriptionId)
        ->and(Payment::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(AccrualPeriod::query()->count())->toBe(12);
});

it('is safe to run twice over the same batch boundary', function (): void {
    Subscription::factory()->count(6)->endedDaysAgo(1)->create();

    $first = app(ExpireSubscriptionsAction::class)(ExpireSubscriptionsData::fromCommand(null, '3'));
    $second = app(ExpireSubscriptionsAction::class)(ExpireSubscriptionsData::fromCommand(null, '3'));

    expect($first)->toBe(6)
        ->and($second)->toBe(0)
        ->and(Subscription::query()->where('status', SubscriptionStatus::EXPIRED)->count())->toBe(6)
        ->and(LedgerEntry::query()->count())->toBe(0);
});
