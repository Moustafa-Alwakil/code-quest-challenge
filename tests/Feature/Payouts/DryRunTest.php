<?php

declare(strict_types=1);

use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * `--dry-run` prints what a run would reserve and writes nothing at all.
 *
 * "Nothing at all" is the whole property, and it is stricter than it sounds:
 * the preview must not release matured holds either, because that is a write
 * the operator did not ask for and it changes what the *real* run would then
 * pay. A preview that quietly moves money is worse than no preview.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('writes nothing, not even the maturation sweep', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithAvailableBalance('ch_dryrun_0001');

    /**
     * A term whose most recent period closed two days ago, so its allocation is
     * recognized but still inside the seven-day hold. Without this the
     * "released nothing" assertion below would be vacuous: everything else in
     * this file matured months ago.
     */
    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => 60_000]);
    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_dryrun_0002',
        capturedAt: CarbonImmutable::now()->subDays(32),
    );

    $stillHeld = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $stillHeld, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    $before = [
        'runs' => PayoutRun::query()->count(),
        'items' => PayoutItem::query()->count(),
        'entries' => LedgerEntry::query()->count(),
        'allocations' => EarningAllocation::query()->whereNull('released_at')->count(),
        'balances' => InstructorBalance::query()->orderBy('instructor_id')->get()->toArray(),
        'balanceUpdatedAt' => DB::table('instructor_balances')->orderBy('instructor_id')->pluck('updated_at')->all(),
    ];

    $this->artisan('payouts:run', ['--dry-run' => true])
        ->expectsOutputToContain('Nothing was written')
        ->assertSuccessful();

    /** The sweep has something to do, so "it did nothing" means something. */
    expect($before['allocations'])->toBeGreaterThan(0);

    expect(PayoutRun::query()->count())->toBe($before['runs'])
        ->and(PayoutItem::query()->count())->toBe($before['items'])
        ->and(LedgerEntry::query()->count())->toBe($before['entries'])
        ->and(EarningAllocation::query()->whereNull('released_at')->count())->toBe($before['allocations'])
        ->and(InstructorBalance::query()->orderBy('instructor_id')->get()->toArray())->toBe($before['balances'])
        /** Even `updated_at` is untouched — MySQL maintains it, so a no-op write would show. */
        ->and(DB::table('instructor_balances')->orderBy('instructor_id')->pluck('updated_at')->all())
        ->toBe($before['balanceUpdatedAt']);

    /** And a real run right afterwards does release it, so the hold was live. */
    $this->artisan('payouts:run')->assertSuccessful();

    expect(InstructorBalance::query()->findOrFail($stillHeld->id)->held_minor)->toBeGreaterThan(0);
});

it('previews the same amounts the real run then reserves', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithAvailableBalance('ch_dryrun_0003');
    instructorWithAvailableBalance('ch_dryrun_0004');

    $payable = (int) InstructorBalance::query()->where('available_minor', '>', 0)->sum('available_minor');

    $this->artisan('payouts:run', ['--dry-run' => true])
        ->expectsOutputToContain('would reserve 2 instructor(s)')
        ->assertSuccessful();

    $this->artisan('payouts:run')->assertSuccessful();

    /** The preview is the number applied, because both read the same balances. */
    expect((int) PayoutItem::query()->sum('amount_minor'))->toBe($payable)
        ->and(PayoutRun::query()->firstOrFail()->total_minor)->toBe($payable);
});

it('does not create a run row for a key that has never been used', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithAvailableBalance('ch_dryrun_0005');

    $this->artisan('payouts:run', ['--run-key' => 'payout:never', '--dry-run' => true])->assertSuccessful();

    expect(PayoutRun::query()->where('run_key', 'payout:never')->exists())->toBeFalse();
});
