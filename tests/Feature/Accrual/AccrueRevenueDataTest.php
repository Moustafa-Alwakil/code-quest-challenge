<?php

declare(strict_types=1);

use App\DTOs\Accrual\AccrueRevenueData;
use App\Enums\ZeroEngagementPolicy;
use Carbon\CarbonImmutable;

/*
 * The boundary where a recognition run's window and its policy are fixed.
 *
 * Both are money decisions. A future date would recognize revenue for time that
 * has not passed, and a dial re-read on a worker would let a retry recognize at
 * a different rate than the run that dispatched it (R27). Everything below is
 * about pinning those two down exactly once.
 *
 * A Feature test rather than a Unit one despite touching no database: the named
 * constructor reads `config/revenue.php`, which is the boundary's job (App\Support
 * may not), and that needs the container. `assertLedgerBalanced()` is not
 * registered, because nothing here moves money.
 */

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    config(['revenue.zero_engagement_policy' => 'platform_retains']);
});

it('defaults the window to today at midnight UTC', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-06-15 23:30:00', 'Africa/Cairo'));

    $data = AccrueRevenueData::fromCommand();

    /**
     * The calendar date is rebuilt, not converted (R26). `->utc()` would move a
     * late-evening Cairo instant back to the 15th at 21:30 — and an
     * early-morning one to the previous day entirely.
     */
    expect($data->asOf->toDateTimeString())->toBe('2024-06-15 00:00:00')
        ->and($data->asOf->getTimezone()->getName())->toBe('UTC');
});

it('reads the clock once, so the window and the stamp cannot disagree', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-06-15 08:00:00'));

    $data = AccrueRevenueData::fromCommand();

    expect($data->recognizedAt->toDateTimeString())->toBe('2024-06-15 08:00:00')
        ->and($data->asOf->toDateString())->toBe($data->recognizedAt->toDateString());
});

it('accepts today and refuses tomorrow', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-06-15 08:00:00'));

    expect(AccrueRevenueData::fromCommand('2024-06-15')->asOf->toDateString())->toBe('2024-06-15')
        ->and(fn () => AccrueRevenueData::fromCommand('2024-06-16'))
        ->toThrow(InvalidArgumentException::class, '--date cannot be in the future');
});

it('allows a past date, because a backfill is a legitimate run', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-06-15 08:00:00'));

    expect(AccrueRevenueData::fromCommand('2024-01-31')->asOf->toDateString())->toBe('2024-01-31');
});

it('rejects a date it cannot parse', function (): void {
    expect(fn () => AccrueRevenueData::fromCommand('last thursday-ish'))
        ->toThrow(InvalidArgumentException::class, '--date must be a date');
});

it('carries the policy dials inward rather than leaving them to be re-read', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2024-06-15 08:00:00'));

    $data = AccrueRevenueData::fromCommand();

    expect($data->instructorShareBps)->toBe(config('revenue.instructor_share_bps'))
        ->and($data->holdDays)->toBe(config('revenue.hold_days'))
        ->and($data->currency)->toBe('EGP')
        ->and($data->zeroEngagementPolicy)->toBe(ZeroEngagementPolicy::PLATFORM_RETAINS);

    /** And hands them on unchanged to each period it recognizes. */
    $period = $data->forPeriod(42);

    expect($period->periodId)->toBe(42)
        ->and($period->instructorShareBps)->toBe($data->instructorShareBps)
        ->and($period->holdDays)->toBe($data->holdDays)
        ->and($period->recognizedAt->toDateTimeString())->toBe($data->recognizedAt->toDateTimeString());
});

it('refuses a zero-engagement policy that is documented but not built', function (): void {
    config(['revenue.zero_engagement_policy' => 'split_equally']);

    /**
     * Falling through to `platform_retains` would quietly retain revenue for
     * the platform under a setting that asked for it to be shared — the worst
     * available reading of a money dial.
     */
    expect(fn () => AccrueRevenueData::fromCommand())
        ->toThrow(InvalidArgumentException::class, 'documented but not built');
});

it('refuses a zero-engagement policy nobody has heard of', function (): void {
    config(['revenue.zero_engagement_policy' => 'whatever_feels_right']);

    expect(fn () => AccrueRevenueData::fromCommand())
        ->toThrow(InvalidArgumentException::class, 'Unknown revenue.zero_engagement_policy');
});

it('refuses a chunk size below one', function (string $chunk): void {
    expect(fn () => AccrueRevenueData::fromCommand(null, $chunk))
        ->toThrow(InvalidArgumentException::class, '--chunk must be a positive integer');
})->with(['0', '-5', 'many']);
