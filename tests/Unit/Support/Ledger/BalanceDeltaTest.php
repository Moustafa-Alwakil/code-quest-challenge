<?php

declare(strict_types=1);

use App\Support\Ledger\BalanceDelta;

/*
 * The named constructors are the vocabulary F05-F09 will post with. Getting one
 * of them backwards is a class of bug `ledger:verify` would catch only after
 * the fact, so each shape is pinned here.
 */

it('recognizes an amount as earned and held, leaving available alone', function (): void {
    $delta = BalanceDelta::recognized(7, 12_345);

    expect($delta->instructorId)->toBe(7)
        ->and($delta->earned)->toBe(12_345)
        ->and($delta->held)->toBe(12_345)
        ->and($delta->available)->toBe(0)
        ->and($delta->reserved)->toBe(0)
        ->and($delta->paid)->toBe(0);
});

it('moves a released hold into available without changing what was earned', function (): void {
    $delta = BalanceDelta::released(7, 5_000);

    expect($delta->held)->toBe(-5_000)
        ->and($delta->available)->toBe(5_000)
        ->and($delta->earned)->toBe(0);
});

it('moves a reservation out of available and back again when it is reversed', function (): void {
    $reserved = BalanceDelta::reserved(7, 9_000);
    $reversed = BalanceDelta::reversed(7, 9_000);

    expect($reserved->available)->toBe(-9_000)
        ->and($reserved->reserved)->toBe(9_000)
        ->and($reversed->available)->toBe(9_000)
        ->and($reversed->reserved)->toBe(-9_000);

    /** A reservation and its reversal cancel out field by field. */
    $net = $reserved->plus($reversed);

    expect([$net->earned, $net->clawedBack, $net->held, $net->available, $net->reserved, $net->paid])
        ->toBe([0, 0, 0, 0, 0, 0]);
});

it('turns a settlement into paid without touching available', function (): void {
    $delta = BalanceDelta::settled(7, 9_000);

    expect($delta->reserved)->toBe(-9_000)
        ->and($delta->paid)->toBe(9_000)
        ->and($delta->available)->toBe(0);
});

it('splits a clawback across held and available and counts the whole of it', function (): void {
    $delta = BalanceDelta::clawedBack(7, fromHeldMinor: 4_000, fromAvailableMinor: 1_500);

    expect($delta->clawedBack)->toBe(5_500)
        ->and($delta->held)->toBe(-4_000)
        ->and($delta->available)->toBe(-1_500);
});

it('merges two movements for the same instructor field by field', function (): void {
    $delta = BalanceDelta::recognized(7, 10_000)->plus(BalanceDelta::released(7, 10_000));

    expect($delta->earned)->toBe(10_000)
        ->and($delta->held)->toBe(0)
        ->and($delta->available)->toBe(10_000);
});

it('refuses to merge deltas belonging to different instructors', function (): void {
    expect(fn () => BalanceDelta::recognized(7, 1)->plus(BalanceDelta::recognized(8, 1)))
        ->toThrow(InvalidArgumentException::class, 'instructors 7 and 8');
});

it('refuses an instructor id that cannot exist', function (): void {
    expect(fn () => BalanceDelta::recognized(0, 1))
        ->toThrow(InvalidArgumentException::class, 'positive instructor id');
});
