<?php

declare(strict_types=1);

use App\Actions\Accrual\RecognizeAccrualPeriodAction;
use App\Actions\Refunds\IssueRefundAction;
use App\DTOs\Accrual\RecognizePeriodData;
use App\DTOs\Refunds\IssueRefundData;
use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Enums\ZeroEngagementPolicy;
use App\Models\AccrualPeriod;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Refunds\RefundOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Interleaved;

/*
 * F09's two races: a refund against `ledger:accrue` on the period it is about
 * to truncate, and two refunds against each other.
 *
 * Not transaction-wrapped (R11). The first race is the interesting one — both
 * parties want the same period, one to recognize it whole and the other to
 * shrink it — and the subscription lock plus the period's status CAS are what
 * decide, with no cache involved.
 */

beforeEach(function (): void {
    Interleaved::open();
});

afterEach(function (): void {
    Interleaved::close();
    assertLedgerBalanced();
});

/**
 * A term with one period closed and unrecognized, and one still running.
 *
 * @return array{0: int, 1: int}
 */
function refundableTermForRace(): array
{
    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => 300_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_refund_race_0001',
        capturedAt: CarbonImmutable::now()->subMonths(5),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    /** The period the refund will land inside: the one covering today. */
    $current = AccrualPeriod::query()
        ->where('subscription_id', $outcome->subscriptionId)
        ->where('status', AccrualPeriodStatus::SCHEDULED)
        ->orderBy('sequence')
        ->firstOrFail();

    return [$outcome->subscriptionId, $current->id];
}

function issueRefund(int $subscriptionId, string $externalRef, bool $full = false): RefundOutcome
{
    return app(IssueRefundAction::class)(IssueRefundData::fromCommand(
        (string) $subscriptionId,
        $externalRef,
        $full,
        CarbonImmutable::now()->toDateString(),
    ));
}

it('lets only one of a refund and an accrual run claim the straddled period', function (): void {
    [$subscriptionId, $periodId] = refundableTermForRace();

    $a = Interleaved::session(Interleaved::SESSION_A);

    /** The refund takes the subscription lock and truncates the period. */
    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $subscriptionId): void {
        $a->beginTransaction();

        expect(issueRefund($subscriptionId, 're_race_0001')->applied)->toBeTrue();
    });

    /**
     * `ledger:accrue` reaches the same period. Its compare-and-swap targets the
     * row the refund is holding, so InnoDB makes it wait rather than letting it
     * recognize the original, longer period underneath.
     */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($periodId): void {
        $b->beginTransaction();

        app(RecognizeAccrualPeriodAction::class)(RecognizePeriodData::forPeriod(
            $periodId,
            7_000,
            7,
            'EGP',
            ZeroEngagementPolicy::PLATFORM_RETAINS,
            CarbonImmutable::now(),
        ));
    });

    expect($blocked)->not->toBeNull('An accrual run recognized a period a refund was truncating.');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** The refund's recognition already happened, so the retry is a no-op. */
    $second = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b) => $b->transaction(fn () => app(RecognizeAccrualPeriodAction::class)(
            RecognizePeriodData::forPeriod($periodId, 7_000, 7, 'EGP', ZeroEngagementPolicy::PLATFORM_RETAINS, CarbonImmutable::now())
        )),
    );

    expect($second->recognized)->toBeFalse()
        ->and(Refund::query()->count())->toBe(1)
        /** The term's liability is discharged exactly once. */
        ->and(ledgerSumFor(LedgerAccountType::DEFERRED_REVENUE, $subscriptionId))->toBe(0);
});

it('records one refund when two sessions race the same term', function (): void {
    [$subscriptionId] = refundableTermForRace();

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $subscriptionId): void {
        $a->beginTransaction();

        expect(issueRefund($subscriptionId, 're_race_0002')->applied)->toBeTrue();
    });

    /** B cannot even read the subscription: A holds it FOR UPDATE. */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($subscriptionId): void {
        $b->beginTransaction();
        issueRefund($subscriptionId, 're_race_0003');
    });

    expect($blocked)->not->toBeNull('Two sessions refunded the same term.');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** With A committed, B sees a refunded term and writes nothing. */
    $second = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): RefundOutcome => $b->transaction(
            fn (): RefundOutcome => issueRefund($subscriptionId, 're_race_0003')
        ),
    );

    expect($second->replayed)->toBeTrue()
        ->and(Refund::query()->count())->toBe(1)
        ->and(Refund::query()->firstOrFail()->external_ref)->toBe('re_race_0002')
        ->and(Subscription::query()->findOrFail($subscriptionId)->status->value)->toBe('refunded');
});
