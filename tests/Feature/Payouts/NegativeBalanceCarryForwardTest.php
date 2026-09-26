<?php

declare(strict_types=1);

use App\Actions\Payouts\ReserveInstructorBalanceAction;
use App\DTOs\Payouts\ReserveInstructorBalanceData;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use App\Services\PayoutRunService;
use App\Support\Payouts\ReservationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * D-7: a clawback bigger than what an instructor had available leaves them
 * owing the platform, and that balance carries forward against future earnings
 * rather than being chased.
 *
 * A payout run must never treat it as payable — not at the configured minimum,
 * and not at a minimum of zero either, which is the case a naive
 * `available >= minimum` test gets wrong.
 *
 * `assertLedgerBalanced()` is not registered: the negative balance is written
 * here by hand, because only F09's clawback produces one legitimately and that
 * feature is not built yet. The snapshot therefore disagrees with the ledger,
 * which `ledger:verify` would correctly report. What is under test is the
 * reservation guard, not the snapshot.
 */

it('never reserves a negative balance, whatever the minimum', function (int $minimum): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = Instructor::factory()->create();

    InstructorBalance::query()->create([
        'instructor_id' => $instructor->id,
        'currency' => 'EGP',
        'earned_minor' => 20_000,
        'clawed_back_minor' => 25_000,
        'available_minor' => -5_000,
    ]);

    $runs = app(PayoutRunService::class);
    $runs->createIfAbsent('payout:negative', CarbonImmutable::now(), CarbonImmutable::now(), 'EGP');
    $run = $runs->findByKey('payout:negative');

    $outcome = app(ReserveInstructorBalanceAction::class)(
        ReserveInstructorBalanceData::forInstructor($run->id, $instructor->id, $minimum, 'EGP')
    );

    expect($outcome->reserved)->toBeFalse()
        ->and($outcome->skippedReason)->toBe(ReservationOutcome::REASON_BELOW_MINIMUM)
        ->and(PayoutItem::query()->count())->toBe(0)
        /** The debt is untouched, waiting to net against the next recognition. */
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->available_minor)->toBe(-5_000);
})->with([
    'configured minimum' => 10_000,
    'no minimum at all' => 0,
]);

it('is not selected by the run sweep either', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = Instructor::factory()->create();

    InstructorBalance::query()->create([
        'instructor_id' => $instructor->id,
        'currency' => 'EGP',
        'earned_minor' => 20_000,
        'clawed_back_minor' => 25_000,
        'available_minor' => -5_000,
    ]);

    /**
     * The keyset query filters in SQL, so a negative balance should never even
     * reach the Action. Both guards exist because the query is an optimization
     * and the Action's check is the one that runs under the row lock.
     */
    $payable = app(PayoutRunService::class)->payableBalances(0, 0, 100);

    expect($payable)->toBe([]);

    $this->artisan('payouts:run', ['--run-key' => 'payout:negative-sweep', '--min-amount' => '0'])
        ->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(0)
        ->and(PayoutRun::query()->firstOrFail()->item_count)->toBe(0);
});

it('pays the reserved amount even when a clawback lands afterwards', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = instructorWithAvailableBalance('ch_negative_0001');
    $available = InstructorBalance::query()->findOrFail($instructor->id)->available_minor;

    $this->artisan('payouts:run', ['--run-key' => 'payout:clawback-after'])->assertSuccessful();

    $item = PayoutItem::query()->firstOrFail();

    /**
     * F09's clawback, landing between reservation and send. The item does not
     * shrink: the money was owed at the moment it left `available`, and the
     * ledger records exactly what was reserved. The debt nets against the next
     * run instead.
     */
    DB::table('instructor_balances')->where('instructor_id', $instructor->id)->update([
        'clawed_back_minor' => DB::raw('clawed_back_minor + 3000'),
        'available_minor' => DB::raw('available_minor - 3000'),
    ]);

    expect($item->amount_minor)->toBe($available)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->available_minor)->toBe(-3_000);

    $this->artisan('payouts:run', ['--run-key' => 'payout:clawback-next'])->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(1);
});
