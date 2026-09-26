<?php

declare(strict_types=1);

use App\Support\Payouts\ReconciliationSchedule;
use Carbon\CarbonImmutable;

/*
 * The backoff ladder and the two windows, tested without a database, a clock or
 * a provider — they are arithmetic, and arithmetic is where the off-by-one
 * lives.
 *
 * The window boundaries matter more than they look: `isWithinNotFoundGrace`
 * deciding wrongly at the edge is the difference between resending a transfer
 * the provider simply had not indexed yet, and waiting fifteen more minutes on
 * one that genuinely never happened.
 */

function scheduleAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-09-15 {$time}");
}

it('widens the gap between asks, then holds at six hours', function (int $checks, string $expected): void {
    expect(ReconciliationSchedule::nextCheckAt(scheduleAt('09:00:00'), $checks)->toDateTimeString())
        ->toBe("2026-09-15 {$expected}");
})->with([
    'first ask' => [1, '09:01:00'],
    'second' => [2, '09:05:00'],
    'third' => [3, '09:30:00'],
    'fourth' => [4, '11:00:00'],
    'fifth' => [5, '15:00:00'],
    'sixth holds' => [6, '15:00:00'],
    'twentieth still holds' => [20, '15:00:00'],
]);

it('treats a zero count as the first ask rather than stepping backwards', function (): void {
    expect(ReconciliationSchedule::nextCheckAt(scheduleAt('09:00:00'), 0)->toDateTimeString())
        ->toBe('2026-09-15 09:01:00');
});

it('gives a not-found fifteen minutes to become visible', function (string $now, bool $within): void {
    expect(ReconciliationSchedule::isWithinNotFoundGrace(scheduleAt('09:00:00'), scheduleAt($now)))->toBe($within);
})->with([
    'immediately after sending' => ['09:00:01', true],
    'fourteen minutes later' => ['09:14:59', true],
    'exactly at the boundary' => ['09:15:00', false],
    'well past it' => ['10:00:00', false],
]);

it('is never within grace for something that was never sent', function (): void {
    expect(ReconciliationSchedule::isWithinNotFoundGrace(null, scheduleAt('09:00:00')))->toBeFalse();
});

it('gives up asking after twenty-four hours, and not before', function (string $now, bool $exhausted): void {
    expect(ReconciliationSchedule::hasExhaustedPatience(scheduleAt('09:00:00'), CarbonImmutable::parse($now)))
        ->toBe($exhausted);
})->with([
    'an hour in' => ['2026-09-15 10:00:00', false],
    'a minute short' => ['2026-09-16 08:59:00', false],
    'exactly at the cap' => ['2026-09-16 09:00:00', true],
    'a day late' => ['2026-09-17 09:00:00', true],
]);

it('never gives up on something that was never sent', function (): void {
    expect(ReconciliationSchedule::hasExhaustedPatience(null, scheduleAt('09:00:00')))->toBeFalse();
});

it('calls a reserved item stranded after half an hour', function (): void {
    expect(ReconciliationSchedule::strandedBefore(scheduleAt('09:00:00'))->toDateTimeString())
        ->toBe('2026-09-15 08:30:00');
});
