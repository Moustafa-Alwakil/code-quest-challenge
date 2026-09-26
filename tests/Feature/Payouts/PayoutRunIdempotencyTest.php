<?php

declare(strict_types=1);

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Models\AccrualPeriod;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/*
 * Required proof #1, reservation half: "running the payout process twice never
 * double-pays."
 *
 * F06 owns the half that has to be right before a single byte reaches a
 * provider — the money leaves `available` atomically, and a second invocation
 * of the same key finds nothing left to reserve. The provider half (transfer
 * count per item, `paid_minor`) belongs to F07's job and is proved there.
 *
 * Nothing here takes the cache lock. That is the point: every guarantee below
 * is a UNIQUE index or a row lock, so the suite backs the claim that if Redis
 * disappeared entirely no instructor would be paid twice.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * An instructor with a real, released balance — earned through recognition and
 * matured past its hold, never assigned to a snapshot by hand.
 */
function instructorWithAvailableBalance(string $externalRef, int $priceMinor = 300_000): Instructor
{
    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => $priceMinor]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        $externalRef,
        capturedAt: CarbonImmutable::now()->subMonths(11),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    return $instructor;
}

it('reserves each instructor once, however many times the run is invoked', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $first = instructorWithAvailableBalance('ch_payout_0001');
    $second = instructorWithAvailableBalance('ch_payout_0002');

    $availableBefore = InstructorBalance::query()->orderBy('instructor_id')->pluck('available_minor', 'instructor_id');

    expect($availableBefore[$first->id])->toBeGreaterThan(0)
        ->and($availableBefore[$second->id])->toBeGreaterThan(0);

    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])->assertSuccessful();

    $fingerprint = fn (): array => [
        'runs' => PayoutRun::query()->orderBy('id')->get(['run_key', 'item_count', 'total_minor', 'status'])->toArray(),
        'items' => PayoutItem::query()->orderBy('id')->get(['payout_run_id', 'instructor_id', 'amount_minor', 'status'])->toArray(),
        'balances' => InstructorBalance::query()->orderBy('instructor_id')->get(['available_minor', 'reserved_minor', 'paid_minor'])->toArray(),
        'entries' => LedgerEntry::query()->where('entry_type', LedgerEntryType::PAYOUT_RESERVED)->count(),
    ];

    $afterFirstRun = $fingerprint();

    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])->assertSuccessful();
    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])->assertSuccessful();

    expect($fingerprint())->toBe($afterFirstRun)
        ->and(PayoutRun::query()->count())->toBe(1)
        ->and(PayoutItem::query()->count())->toBe(2);

    /** The money left `available` exactly once, and is now in transit. */
    foreach ([$first, $second] as $instructor) {
        $balance = InstructorBalance::query()->findOrFail($instructor->id);

        expect($balance->available_minor)->toBe(0)
            ->and($balance->reserved_minor)->toBe($availableBefore[$instructor->id])
            ->and($balance->paid_minor)->toBe(0)
            /** Outstanding is unchanged: reserving moves money, it does not spend it. */
            ->and($balance->outstandingMinor())->toBe($balance->available_minor + $balance->held_minor + $balance->reserved_minor);
    }
});

it('posts one reservation per item, keyed per instructor on both sides', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = instructorWithAvailableBalance('ch_payout_0003');
    $reservedMinor = InstructorBalance::query()->findOrFail($instructor->id)->available_minor;

    $this->artisan('payouts:run')->assertSuccessful();

    $item = PayoutItem::query()->firstOrFail();

    $legs = LedgerEntry::query()
        ->where('entry_type', LedgerEntryType::PAYOUT_RESERVED)
        ->where('reference_type', 'payout_item')
        ->where('reference_id', $item->id)
        ->get();

    expect($legs)->toHaveCount(2)
        ->and($legs->sum('amount_minor'))->toBe(0)
        ->and($item->amount_minor)->toBe($reservedMinor)
        ->and($item->status)->toBe(PayoutItemStatus::RESERVED)
        /** What we owed the instructor is now money in transit to that same instructor (R5). */
        ->and(ledgerSumFor(LedgerAccountType::PROVIDER_IN_TRANSIT, $instructor->id))->toBe(-$reservedMinor);
});

it('gives a new run key nothing to do when no new earnings have matured', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithAvailableBalance('ch_payout_0004');

    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])->assertSuccessful();

    /**
     * The second key is a genuinely new run. It still pays nothing, because
     * reservation already moved the money out of `available` — the guarantee
     * the unique item index alone could not give.
     */
    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-10'])
        ->expectsOutputToContain('reserved 0')
        ->assertSuccessful();

    expect(PayoutRun::query()->count())->toBe(2)
        ->and(PayoutItem::query()->count())->toBe(1);
});

it('refuses to resume a completed run, so newly matured earnings wait for a new key', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithAvailableBalance('ch_payout_0005');

    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])->assertSuccessful();

    /**
     * What F08 leaves behind once every item of the run has resolved. Only the
     * run's status is touched: it is a reporting state, not a ledger fact, so
     * moving it by hand invents no money.
     */
    PayoutRun::query()->update(['status' => PayoutRunStatus::COMPLETED]);

    $laterEarner = instructorWithAvailableBalance('ch_payout_0006');

    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])
        ->expectsOutputToContain('already completed')
        ->assertSuccessful();

    /**
     * Re-opening the run to sweep up this instructor would mix two periods'
     * payouts under one key. They wait for a new one instead.
     */
    expect(PayoutItem::query()->count())->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($laterEarner->id)->available_minor)->toBeGreaterThan(0);

    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-10'])->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(2)
        ->and(InstructorBalance::query()->findOrFail($laterEarner->id)->available_minor)->toBe(0);
});
