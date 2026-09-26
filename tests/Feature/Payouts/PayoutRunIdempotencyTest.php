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
 * **Required proof #1**: running the payout process twice never double-pays.
 *
 * End to end, on the sync queue, against a scripted provider that succeeds:
 * one run, one item per eligible instructor, the provider's own transfer count
 * at exactly one per item, `paid_minor` incremented once, and `ledger:verify`
 * green afterwards.
 *
 * Three independent mechanisms produce that, and none of them is the cache
 * lock: UNIQUE `(payout_run_id, instructor_id)` within a run, reserve-before-
 * send across runs, and the provider's dedup on `idempotency_key`. Nothing here
 * takes a lock, which is what makes this the proof rather than a demonstration.
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

it('pays each instructor once, however many times the run is invoked', function (): void {
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
        'entries' => LedgerEntry::query()->count(),
    ];

    $afterFirstRun = $fingerprint();

    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])->assertSuccessful();
    $this->artisan('payouts:run', ['--run-key' => 'payout:2026-09'])->assertSuccessful();

    expect($fingerprint())->toBe($afterFirstRun)
        ->and(PayoutRun::query()->count())->toBe(1)
        ->and(PayoutItem::query()->count())->toBe(2);

    foreach ([$first, $second] as $instructor) {
        $item = PayoutItem::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $balance = InstructorBalance::query()->findOrFail($instructor->id);

        /** The provider moved this money once, whatever we did afterwards. */
        expect(provider()->transferCount($item->idempotency_key))->toBe(1)
            ->and($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
            ->and($item->amount_minor)->toBe($availableBefore[$instructor->id])
            /** Paid once, and nothing is left in transit or available. */
            ->and($balance->paid_minor)->toBe($availableBefore[$instructor->id])
            ->and($balance->reserved_minor)->toBe(0)
            ->and($balance->available_minor)->toBe(0)
            ->and($balance->outstandingMinor())->toBe($balance->available_minor + $balance->held_minor + $balance->reserved_minor);
    }

    /** The run closed cleanly, because every item reached a terminal state. */
    expect(PayoutRun::query()->firstOrFail()->status)->toBe(PayoutRunStatus::COMPLETED);
});

it('posts a reservation and a settlement per item, keyed per instructor', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = instructorWithAvailableBalance('ch_payout_0003');
    $paidMinor = InstructorBalance::query()->findOrFail($instructor->id)->available_minor;

    $this->artisan('payouts:run')->assertSuccessful();

    $item = PayoutItem::query()->firstOrFail();

    $legsOf = fn (LedgerEntryType $type) => LedgerEntry::query()
        ->where('entry_type', $type)
        ->where('reference_type', 'payout_item')
        ->where('reference_id', $item->id)
        ->get();

    expect($legsOf(LedgerEntryType::PAYOUT_RESERVED))->toHaveCount(2)
        ->and($legsOf(LedgerEntryType::PAYOUT_RESERVED)->sum('amount_minor'))->toBe(0)
        ->and($legsOf(LedgerEntryType::PAYOUT_SETTLED))->toHaveCount(2)
        ->and($legsOf(LedgerEntryType::PAYOUT_SETTLED)->sum('amount_minor'))->toBe(0)
        ->and($item->amount_minor)->toBe($paidMinor)
        ->and($item->status)->toBe(PayoutItemStatus::SUCCEEDED)
        ->and($item->provider_reference)->not->toBeNull()
        /**
         * In transit and back out again: the instructor's liability went to
         * `provider_in_transit` and then left the platform as cash, so the
         * account returns to exactly zero (R5).
         */
        ->and(ledgerSumFor(LedgerAccountType::PROVIDER_IN_TRANSIT, $instructor->id))->toBe(0);

    /**
     * What the ledger still owes this instructor is exactly what the hold is
     * keeping back — the earnings from the period whose seven days have not
     * expired yet. Credit-normal, so the raw sum is its negation.
     */
    $held = InstructorBalance::query()->findOrFail($instructor->id)->held_minor;

    expect($held)->toBeGreaterThan(0)
        ->and(ledgerSumFor(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructor->id))->toBe(-$held);
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
