<?php

declare(strict_types=1);

use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
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

    /** Different prices, so one balance clears a minimum the other does not. */
    $rich = instructorWithAvailableBalance('ch_reserve_0005', priceMinor: 300_000);
    $lean = instructorWithAvailableBalance('ch_reserve_0006', priceMinor: 120_000);

    $richAvailable = InstructorBalance::query()->findOrFail($rich->id)->available_minor;
    $leanAvailable = InstructorBalance::query()->findOrFail($lean->id)->available_minor;

    expect($richAvailable)->toBeGreaterThan($leanAvailable);

    /**
     * The state a crash mid-reservation leaves: the run exists, some
     * instructors have committed items, and others are still sitting on a
     * payable balance. Reproduced here with a minimum only one of them clears,
     * which reaches exactly that state without pretending to kill a process.
     */
    $partialMinimum = $richAvailable;

    $this->artisan('payouts:run', ['--run-key' => 'payout:resume', '--min-amount' => (string) $partialMinimum])
        ->assertSuccessful();

    expect(PayoutItem::query()->count())->toBe(1);

    /** The resume: same key, full minimum. The existing item is untouched. */
    $existingItemId = PayoutItem::query()->firstOrFail()->id;

    $this->artisan('payouts:run', ['--run-key' => 'payout:resume', '--min-amount' => '0'])
        ->expectsOutputToContain('reserved 1')
        ->assertSuccessful();

    $items = PayoutItem::query()->orderBy('id')->get();

    expect($items)->toHaveCount(2)
        ->and($items[0]->id)->toBe($existingItemId)
        ->and(PayoutRun::query()->count())->toBe(1)
        ->and(PayoutRun::query()->firstOrFail()->item_count)->toBe(2)
        ->and($items->every(fn (PayoutItem $item): bool => $item->status === PayoutItemStatus::RESERVED))->toBeTrue()
        ->and(PayoutRun::query()->firstOrFail()->status)->toBe(PayoutRunStatus::DISPATCHED);

    /** Both balances are out of `available` exactly once. */
    expect(InstructorBalance::query()->sum('available_minor'))->toBe(0);
});
