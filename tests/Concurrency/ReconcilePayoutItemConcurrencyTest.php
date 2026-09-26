<?php

declare(strict_types=1);

use App\Actions\Payouts\ProcessPayoutItemAction;
use App\Actions\Payouts\ReconcilePayoutItemAction;
use App\Actions\Payouts\ReserveInstructorBalanceAction;
use App\DTOs\Payouts\ReserveInstructorBalanceData;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutItemStatus;
use App\Models\AccrualPeriod;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutItem;
use App\Models\Plan;
use App\Models\User;
use App\Services\PayoutRunService;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Interleaved;

/*
 * F08's hardest row: a reconciliation sweep and a late worker retry reaching
 * the same item at the same moment.
 *
 * Both will ask the provider, both will be told `succeeded`, and both will try
 * to settle. Exactly one may move money. Two independent mechanisms say so —
 * the `submitted|unknown → succeeded` compare-and-swap, and the ledger's unique
 * key on (entry_type, reference_type, reference_id, account_type, account_id) —
 * and this file exists to prove neither is load-bearing alone.
 *
 * Not transaction-wrapped (R11): under RefreshDatabase the second session could
 * not see the first's uncommitted settlement, so nothing would ever contend.
 */

beforeEach(function (): void {
    Interleaved::open();
});

afterEach(function (): void {
    Interleaved::close();
    assertLedgerBalanced();
});

/**
 * A payout item left `unknown` by a timeout whose transfer actually succeeded.
 */
function uncertainItem(): PayoutItem
{
    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => 300_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_reconcile_race_0001',
        capturedAt: CarbonImmutable::now()->subMonths(11),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    $runs = app(PayoutRunService::class);
    $runs->createIfAbsent('payout:race', CarbonImmutable::now(), CarbonImmutable::now(), 'EGP');
    $run = $runs->findByKey('payout:race');

    app(ReserveInstructorBalanceAction::class)(
        ReserveInstructorBalanceData::forInstructor($run->id, $instructor->id, 0, 'EGP')
    );

    /** The money moves and the answer is lost. */
    app(ScriptedMockProvider::class);
    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    app(ProcessPayoutItemAction::class)(PayoutItem::query()->firstOrFail()->id);

    return PayoutItem::query()->firstOrFail();
}

it('settles once when a sweep and a retry reach the same item together', function (): void {
    $item = uncertainItem();

    expect($item->status)->toBe(PayoutItemStatus::UNKNOWN);

    $a = Interleaved::session(Interleaved::SESSION_A);

    /** The sweep asks, is told `succeeded`, and settles — uncommitted. */
    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $item): void {
        $a->beginTransaction();

        expect(app(ReconcilePayoutItemAction::class)($item->id))->toBe(PayoutItemStatus::SUCCEEDED);
    });

    /**
     * A worker retry arrives at the same item. It blocks on the row the sweep
     * is holding — the compare-and-swap is what serializes them, not a lock we
     * took deliberately.
     */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($item): void {
        $b->beginTransaction();
        app(ProcessPayoutItemAction::class)($item->id);
    });

    expect($blocked)->not->toBeNull('Session B settled an item session A was already settling.');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** With A committed, B finds a terminal item and does nothing at all. */
    $second = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): PayoutItemStatus => $b->transaction(
            fn (): PayoutItemStatus => app(ProcessPayoutItemAction::class)($item->id)
        ),
    );

    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($second)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(PayoutItem::query()->findOrFail($item->id)->status)->toBe(PayoutItemStatus::SUCCEEDED)
        /** One settlement posting, two legs. Not two settlements. */
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(2)
        ->and($balance->paid_minor)->toBe($item->amount_minor)
        ->and($balance->reserved_minor)->toBe(0)
        /** And the provider moved the money once, across all of it. */
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1);
});

it('lets the retry settle when the sweep rolls back', function (): void {
    $item = uncertainItem();

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $item): void {
        $a->beginTransaction();

        expect(app(ReconcilePayoutItemAction::class)($item->id))->toBe(PayoutItemStatus::SUCCEEDED);
    });

    /** The sweep's worker dies before committing. Nothing it did survives. */
    Interleaved::as(Interleaved::SESSION_A, fn () => $a->rollBack());

    $retry = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): PayoutItemStatus => $b->transaction(
            fn (): PayoutItemStatus => app(ProcessPayoutItemAction::class)($item->id)
        ),
    );

    $balance = InstructorBalance::query()->findOrFail($item->instructor_id);

    expect($retry)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and(LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_SETTLED)->count())->toBe(2)
        ->and($balance->paid_minor)->toBe($item->amount_minor)
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1);
});
