<?php

declare(strict_types=1);

namespace App\Support\Ledger;

/**
 * The shape an instructor's recomputed balance comes back in (F03).
 *
 * Pure: it describes what the ledger says an instructor is owed, with no idea
 * how that was established. It lives here rather than as a static on the
 * balance service because it is a value, not a query — and because a static
 * helper on a service is the one member a contract cannot express, so it would
 * have quietly forced every caller back to the concrete class.
 */
final class BalanceTotals
{
    /**
     * What the ledger says about an instructor it has never mentioned.
     *
     * Not an absence: an instructor with no entries is genuinely owed nothing,
     * and `ledger:verify` compares their snapshot against exactly this.
     *
     * @return array{earned: int, clawed_back: int, held: int, available: int, reserved: int, paid: int, currencies: list<string>}
     */
    public static function zero(): array
    {
        return [
            'earned' => 0,
            'clawed_back' => 0,
            'held' => 0,
            'available' => 0,
            'reserved' => 0,
            'paid' => 0,
            'currencies' => [],
        ];
    }
}
