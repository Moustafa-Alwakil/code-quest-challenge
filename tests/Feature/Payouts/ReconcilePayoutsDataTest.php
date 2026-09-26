<?php

declare(strict_types=1);

use App\DTOs\Payouts\ReconcilePayoutsData;
use Carbon\CarbonImmutable;

/*
 * One clock read per sweep (R27).
 *
 * Both passes have to select against the same instant, or an item can fall
 * between them: due for the uncertain sweep at one "now" and not yet stranded
 * at another. Carrying the instant is what makes that impossible rather than
 * unlikely.
 */

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('carries the instant both sweeps select against', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 09:05:00'));

    expect(ReconcilePayoutsData::fromCommand()->asOf->toDateTimeString())->toBe('2026-09-15 09:05:00');
});

it('defaults the limit and accepts an override', function (): void {
    expect(ReconcilePayoutsData::fromCommand()->limit)->toBe(500)
        ->and(ReconcilePayoutsData::fromCommand('25')->limit)->toBe(25);
});

it('refuses a limit that is not a positive integer', function (string $limit): void {
    expect(fn () => ReconcilePayoutsData::fromCommand($limit))
        ->toThrow(InvalidArgumentException::class, '--limit must be a positive integer');
})->with(['0', '-1', 'all']);
