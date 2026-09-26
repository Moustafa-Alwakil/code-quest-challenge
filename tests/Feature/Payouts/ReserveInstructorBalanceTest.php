<?php

declare(strict_types=1);

use App\Actions\Payouts\ReserveInstructorBalanceAction;
use App\DTOs\Payouts\ReserveInstructorBalanceData;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use App\Services\PayoutRunService;
use Carbon\CarbonImmutable;

/*
 * Which balances a run picks up, and which it deliberately leaves behind.
 *
 * The rows below are F06's edge-case table: below the minimum, negative,
 * exactly at the minimum, and an instructor the ledger has never mentioned.
 * Each is a decision about somebody's money, not a filter detail.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('skips a balance below the minimum and carries it forward', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = instructorWithAvailableBalance('ch_reserve_0001');
    $available = InstructorBalance::query()->findOrFail($instructor->id)->available_minor;

    /** A minimum just above what they have earned. */
    $this->artisan('payouts:run', ['--min-amount' => (string) ($available + 1)])
        ->expectsOutputToContain('reserved 0')
        ->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(0)
        /** Carried, not lost: the next run at a lower minimum pays it. */
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->available_minor)->toBe($available);

    $this->artisan('payouts:run', ['--run-key' => 'payout:later', '--min-amount' => (string) $available])
        ->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(1)
        ->and(PayoutItem::query()->firstOrFail()->amount_minor)->toBe($available);
});

it('pays a balance exactly at the minimum', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = instructorWithAvailableBalance('ch_reserve_0002');
    $available = InstructorBalance::query()->findOrFail($instructor->id)->available_minor;

    $this->artisan('payouts:run', ['--min-amount' => (string) $available])->assertSuccessful();

    expect(PayoutItem::query()->firstOrFail()->amount_minor)->toBe($available);
});

it('does not reserve a balance that is already fully reserved', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = instructorWithAvailableBalance('ch_reserve_0003');

    $this->artisan('payouts:run', ['--run-key' => 'payout:first'])->assertSuccessful();

    expect(InstructorBalance::query()->findOrFail($instructor->id)->available_minor)->toBe(0);

    /**
     * Zero is not "a small amount": with a minimum of zero the amount clears
     * the threshold, and only the `<= 0` guard stops an item for nothing being
     * created and a zero-amount transfer being sent to a provider.
     */
    $this->artisan('payouts:run', ['--run-key' => 'payout:second', '--min-amount' => '0'])
        ->expectsOutputToContain('reserved 0')
        ->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(1);
});

it('does not select an instructor the ledger has never mentioned', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $earner = instructorWithAvailableBalance('ch_reserve_0004');
    $newcomer = Instructor::factory()->create();

    $this->artisan('payouts:run')->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(1)
        ->and(PayoutItem::query()->firstOrFail()->instructor_id)->toBe($earner->id)
        ->and(InstructorBalance::query()->find($newcomer->id))->toBeNull();
});

it('resumes a run that crashed after reserving only some instructors', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $early = instructorWithAvailableBalance('ch_reserve_0005', priceMinor: 300_000);
    $late = instructorWithAvailableBalance('ch_reserve_0006', priceMinor: 120_000);

    $earlyAvailable = InstructorBalance::query()->findOrFail($early->id)->available_minor;
    $lateAvailable = InstructorBalance::query()->findOrFail($late->id)->available_minor;

    /**
     * Exactly the state a crash mid-reservation leaves behind: the run row is
     * committed, the first instructor's item and its posting are committed, and
     * the process died before it reached the second instructor or dispatched
     * anything. Built through the same Action the command uses, so the ledger
     * is as consistent as a real half-finished run would be.
     */
    $runs = app(PayoutRunService::class);
    $runs->createIfAbsent('payout:resume', CarbonImmutable::now(), CarbonImmutable::now(), 'EGP');
    $run = $runs->findByKey('payout:resume');

    app(ReserveInstructorBalanceAction::class)(
        ReserveInstructorBalanceData::forInstructor($run->id, $early->id, 0, 'EGP')
    );

    expect(PayoutItem::query()->count())->toBe(1)
        ->and(PayoutItem::query()->firstOrFail()->status)->toBe(PayoutItemStatus::RESERVED);

    $existingItemId = PayoutItem::query()->firstOrFail()->id;

    /** The resume: same key. It reserves the one that was missed... */
    $this->artisan('payouts:run', ['--run-key' => 'payout:resume', '--min-amount' => '0'])
        ->expectsOutputToContain('reserved 1')
        ->assertSuccessful();

    $items = PayoutItem::query()->orderBy('id')->get();

    expect($items)->toHaveCount(2)
        ->and($items[0]->id)->toBe($existingItemId)
        ->and(PayoutRun::query()->count())->toBe(1)
        ->and(PayoutRun::query()->firstOrFail()->item_count)->toBe(2);

    /**
     * ...and dispatches *both*, including the orphan the crashed invocation
     * left behind. That is why the dispatch list is read from the database
     * rather than from what this invocation happened to reserve.
     */
    expect($items->every(fn (PayoutItem $item): bool => $item->status === PayoutItemStatus::SUCCEEDED))->toBeTrue()
        ->and(PayoutRun::query()->firstOrFail()->status)->toBe(PayoutRunStatus::COMPLETED);

    /** Both balances left `available` exactly once and were paid exactly once. */
    expect((int) InstructorBalance::query()->sum('available_minor'))->toBe(0)
        ->and(InstructorBalance::query()->findOrFail($early->id)->paid_minor)->toBe($earlyAvailable)
        ->and(InstructorBalance::query()->findOrFail($late->id)->paid_minor)->toBe($lateAvailable);
});
