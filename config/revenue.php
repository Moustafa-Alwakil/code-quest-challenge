<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Revenue policy dials
    |--------------------------------------------------------------------------
    |
    | Every policy decision in docs/PLAN.md is a visible value here, never a
    | literal buried in a Service or an Action. App\Support never reads this
    | file: the entry point (or a DTO's named constructor) reads a value and
    | passes it inward.
    |
    */

    /**
     * The single currency the ledger operates in. All amounts are integer
     * minor units (piastres, EGP x100) end to end (D-4).
     */
    'currency' => 'EGP',

    /**
     * Share of recognized revenue that goes to the instructor pool, in basis
     * points (0...10000). 7000 = 70%. The pool is floored and the platform
     * absorbs the sub-unit, so an instructor is never short-changed by an
     * invisible remainder (D-4).
     */
    'instructor_share_bps' => 7000,

    /**
     * Days an allocation stays held before it becomes available for payout.
     * A full refund inside the window is a free clawback (D-6).
     */
    'hold_days' => 7,

    /**
     * Balances below this are skipped by a payout run and carry forward, so
     * the platform does not pay provider fees to move EGP 3 (D-7).
     */
    'minimum_payout_minor' => 10000,

    /**
     * What happens to a period's revenue when a subscription generated no
     * engagement at all: there is no defensible proportion, so the platform
     * retains it (D-3). The alternative — an equal split among the enrolled
     * instructors — is equally arguable, which is why this is a dial.
     */
    'zero_engagement_policy' => 'platform_retains',

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    */

    /**
     * Selects the payout provider implementation (F07): 'mock' | 'random' | 'scripted'.
     */
    'payout_provider' => env('PAYOUT_PROVIDER', 'random'),

    /**
     * Outcome weights for RandomMockProvider (F07), as integers out of 100.
     * Integers, not floats, so the distribution is exact and testable.
     *
     * @var array{success: int, permanent_failure: int, timeout_after_success: int, delayed_confirmation: int}
     */
    'provider_outcomes' => [
        'success' => 70,
        'permanent_failure' => 10,
        'timeout_after_success' => 10,
        'delayed_confirmation' => 10,
    ],

    /**
     * Status checks a 'delayed_confirmation' transfer stays pending for before
     * it flips to success (video scenario 5).
     */
    'provider_confirm_after_checks' => 2,

];
