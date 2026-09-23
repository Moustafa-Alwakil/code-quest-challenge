<?php

declare(strict_types=1);

use App\Exceptions\ZeroWeightException;
use App\Support\Allocator;

it('gives every key zero when the total is zero', function (): void {
    expect(Allocator::largestRemainder(0, [1, 2, 3]))->toBe([0, 0, 0]);
});

it('gives a single piastre to the lowest key when remainders tie', function (): void {
    expect(Allocator::largestRemainder(1, [3, 3, 3]))->toBe([1, 0, 0]);
});

it('gives the whole total to a single key', function (): void {
    expect(Allocator::largestRemainder(7_500, ['solo' => 42]))->toBe(['solo' => 7_500]);
});

it('allocates 1000 across three equal weights as 334/333/333', function (): void {
    $shares = Allocator::largestRemainder(1_000, [3, 3, 3]);

    expect($shares)->toBe([334, 333, 333])
        ->and(array_sum($shares))->toBe(1_000);
});

it('allocates 100 across seven equal weights as 15/15/14/14/14/14/14', function (): void {
    $shares = Allocator::largestRemainder(100, array_fill(0, 7, 1));

    expect($shares)->toBe([15, 15, 14, 14, 14, 14, 14])
        ->and(array_sum($shares))->toBe(100);
});

it('keeps the sum exact when one weight dwarfs another', function (): void {
    $shares = Allocator::largestRemainder(1_000, ['tiny' => 1, 'huge' => 1_000_000]);

    expect($shares['tiny'])->toBe(0)
        ->and($shares['huge'])->toBe(1_000)
        ->and(array_sum($shares))->toBe(1_000);
});

it('preserves non-sequential keys and breaks ties by ascending key', function (): void {
    $shares = Allocator::largestRemainder(1_000, [7 => 3, 42 => 3, 3 => 3]);

    expect(array_keys($shares))->toBe([7, 42, 3])
        ->and($shares)->toBe([7 => 333, 42 => 333, 3 => 334])
        ->and(array_sum($shares))->toBe(1_000);
});

it('returns keys that received nothing', function (): void {
    $shares = Allocator::largestRemainder(100, ['a' => 0, 'b' => 5]);

    expect($shares)->toBe(['a' => 0, 'b' => 100]);
});

it('is deterministic across repeated calls', function (): void {
    $weights = [11 => 17, 2 => 5, 30 => 17, 4 => 1];

    $first = Allocator::largestRemainder(9_999, $weights);

    foreach (range(1, 5) as $ignored) {
        expect(Allocator::largestRemainder(9_999, $weights))->toBe($first);
    }
});

it('refuses to decide what zero engagement means', function (): void {
    expect(fn () => Allocator::largestRemainder(1_000, [0, 0, 0]))
        ->toThrow(ZeroWeightException::class);
});

it('rejects an empty weight set', function (): void {
    expect(fn () => Allocator::largestRemainder(1_000, []))
        ->toThrow(ZeroWeightException::class);
});

it('rejects a negative total', function (): void {
    expect(fn () => Allocator::largestRemainder(-1, [1, 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a negative weight', function (): void {
    expect(fn () => Allocator::largestRemainder(100, [1, -1]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a non-integer weight', function (): void {
    expect(fn () => Allocator::largestRemainder(100, [1, 1.5]))
        ->toThrow(InvalidArgumentException::class);
});

it('guards against 64-bit overflow on the total', function (): void {
    expect(fn () => Allocator::largestRemainder(1_000_000_000_001, [1, 1]))
        ->toThrow(OverflowException::class);
});

it('guards against 64-bit overflow on a weight', function (): void {
    expect(fn () => Allocator::largestRemainder(1_000, [1, 1_000_001]))
        ->toThrow(OverflowException::class);
});

it('accepts the guard bounds themselves', function (): void {
    $shares = Allocator::largestRemainder(1_000_000_000_000, [1_000_000, 1_000_000]);

    expect(array_sum($shares))->toBe(1_000_000_000_000);
});

it('sums to the total for a thousand random cases', function (): void {
    $seed = testSeed();
    mt_srand($seed);

    foreach (range(1, 1_000) as $case) {
        $total = mt_rand(0, 100_000_000);
        $keyCount = mt_rand(1, 12);

        $weights = [];
        for ($i = 0; $i < $keyCount; $i++) {
            $weights[mt_rand(1, 10_000)] = mt_rand(0, 1_000_000);
        }

        if (array_sum($weights) === 0) {
            $weights[array_key_first($weights)] = 1;
        }

        $shares = Allocator::largestRemainder($total, $weights);

        $context = sprintf('seed %d, case %d, total %d, weights %s', $seed, $case, $total, json_encode($weights));

        expect(array_sum($shares))->toBe($total, $context)
            ->and(array_keys($shares))->toBe(array_keys($weights), $context)
            ->and(min($shares))->toBeGreaterThanOrEqual(0, $context);
    }
});
