<?php

declare(strict_types=1);

/*
 * config/revenue.php is the one file every later feature reads its policy from.
 * A typo here is silent until money is wrong, so the shape is asserted directly.
 */

it('defines every policy dial', function (string $key): void {
    expect(config("revenue.{$key}"))->not->toBeNull();
})->with([
    'currency',
    'instructor_share_bps',
    'hold_days',
    'minimum_payout_minor',
    'zero_engagement_policy',
    'payout_provider',
    'provider_outcomes',
    'provider_confirm_after_checks',
]);

it('uses a single three-letter currency', function (): void {
    expect(config('revenue.currency'))->toMatch('/^[A-Z]{3}$/');
});

it('keeps the instructor share within basis-point bounds', function (): void {
    $shareBps = config('revenue.instructor_share_bps');

    expect($shareBps)->toBeInt()
        ->toBeGreaterThanOrEqual(0)
        ->toBeLessThanOrEqual(10_000);
});

it('holds integer day and minor-unit dials', function (string $key): void {
    expect(config("revenue.{$key}"))->toBeInt()->toBeGreaterThanOrEqual(0);
})->with(['hold_days', 'minimum_payout_minor', 'provider_confirm_after_checks']);

it('names a zero-engagement policy the recognizer knows', function (): void {
    expect(config('revenue.zero_engagement_policy'))->toBeIn(['platform_retains', 'split_equally']);
});

it('weights the provider outcomes as integers summing to one hundred', function (): void {
    $outcomes = config('revenue.provider_outcomes');

    expect(array_keys($outcomes))
        ->toBe(['success', 'permanent_failure', 'timeout_after_success', 'delayed_confirmation'])
        ->and(array_sum($outcomes))->toBe(100);

    foreach ($outcomes as $outcome => $weight) {
        expect($weight)->toBeInt("weight for '{$outcome}'")->toBeGreaterThanOrEqual(0);
    }
});
