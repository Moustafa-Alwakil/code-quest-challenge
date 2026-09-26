<?php

declare(strict_types=1);

use App\DTOs\Payouts\RunPayoutsData;
use Carbon\CarbonImmutable;

/*
 * The run key is the idempotency key of the whole command, so where it is
 * resolved matters: here, once, at the boundary. Defaulting it inside the
 * Action would make "the same run" depend on when the Action read the clock,
 * which across a month boundary is two different runs (R27).
 *
 * A Feature test rather than a Unit one because the named constructor reads
 * `config/revenue.php`, which is the boundary's job. Nothing here moves money,
 * so the invariant hook is not registered.
 */

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('defaults the run key to the current month', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 03:00:00'));

    $data = RunPayoutsData::fromCommand();

    expect($data->runKey)->toBe('payout:2026-09')
        ->and($data->scheduledFor->toDateString())->toBe('2026-09-15')
        ->and($data->lockKey())->toBe('payouts:run:payout:2026-09');
});

it('keys the run and dates it from one clock read', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 23:59:59'));

    $data = RunPayoutsData::fromCommand();

    /** A run keyed to September must not be dated in October. */
    expect($data->runKey)->toBe('payout:2026-09')
        ->and($data->scheduledFor->format('Y-m'))->toBe('2026-09')
        ->and($data->startedAt->toDateString())->toBe($data->scheduledFor->toDateString());
});

it('honours an explicit run key', function (): void {
    expect(RunPayoutsData::fromCommand('  payout:manual-2026-09  ')->runKey)->toBe('payout:manual-2026-09');
});

it('refuses a run key longer than the column', function (): void {
    expect(fn () => RunPayoutsData::fromCommand(str_repeat('k', 65)))
        ->toThrow(InvalidArgumentException::class, 'at most 64 characters');
});

it('takes the payout minimum from config unless overridden', function (): void {
    expect(RunPayoutsData::fromCommand()->minimumAmountMinor)->toBe(config('revenue.minimum_payout_minor'))
        ->and(RunPayoutsData::fromCommand(null, '250')->minimumAmountMinor)->toBe(250)
        /** Zero means "pay everything positive", which is a legitimate request. */
        ->and(RunPayoutsData::fromCommand(null, '0')->minimumAmountMinor)->toBe(0);
});

it('refuses a minimum that is not a non-negative integer', function (string $minimum): void {
    expect(fn () => RunPayoutsData::fromCommand(null, $minimum))
        ->toThrow(InvalidArgumentException::class, '--min-amount must be a non-negative integer');
})->with(['-1', '10.50', 'lots']);

it('hands the maturation sweep the same instant the run started at', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 03:00:00'));

    $data = RunPayoutsData::fromCommand();

    /**
     * A payout run has to decide availability against the clock it reserves
     * against: releasing at one instant and reserving at another would let an
     * allocation mature into a balance this run then fails to see.
     */
    expect($data->release()->asOf->toDateTimeString())->toBe($data->startedAt->toDateTimeString())
        ->and($data->release()->currency)->toBe($data->currency);
});
