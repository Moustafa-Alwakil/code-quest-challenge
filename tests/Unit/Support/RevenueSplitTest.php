<?php

declare(strict_types=1);

use App\Support\RevenueSplit;

it('gives the instructor pool nothing at zero basis points', function (): void {
    expect(RevenueSplit::split(9_999, 0))->toBe([0, 9_999]);
});

it('gives the platform nothing at ten thousand basis points', function (): void {
    expect(RevenueSplit::split(9_999, 10_000))->toBe([9_999, 0]);
});

it('floors the pool so the platform absorbs the sub-unit', function (): void {
    // 7000 bps of 999 is 699.3 — the instructor gets 699, the platform keeps the 0.3.
    expect(RevenueSplit::split(999, 7_000))->toBe([699, 300]);
});

it('splits a zero gross into two zeroes', function (): void {
    expect(RevenueSplit::split(0, 7_000))->toBe([0, 0]);
});

it('rejects a negative gross', function (): void {
    expect(fn () => RevenueSplit::split(-1, 7_000))->toThrow(InvalidArgumentException::class);
});

it('rejects a share outside zero to ten thousand basis points', function (int $shareBps): void {
    expect(fn () => RevenueSplit::split(1_000, $shareBps))->toThrow(InvalidArgumentException::class);
})->with([-1, 10_001]);

it('keeps platform plus pool equal to gross across a fuzzed range', function (): void {
    mt_srand(testSeed());

    foreach (range(1, 1_000) as $case) {
        $gross = mt_rand(0, 100_000_000);
        $shareBps = mt_rand(0, 10_000);

        [$pool, $platform] = RevenueSplit::split($gross, $shareBps);

        $context = sprintf('case %d, gross %d, bps %d', $case, $gross, $shareBps);

        expect($pool + $platform)->toBe($gross, $context)
            ->and($pool)->toBeGreaterThanOrEqual(0, $context)
            ->and($platform)->toBeGreaterThanOrEqual(0, $context);
    }
});
