<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Splits gross recognized revenue into the instructor pool and the platform cut (D-4).
 *
 * The pool is floored and the platform takes what is left, so the platform — not
 * an instructor — absorbs the fractional piastre. A platform can account for a
 * rounding bucket; an instructor cannot be short-changed by an invisible remainder.
 */
final class RevenueSplit
{
    private const BPS_DENOMINATOR = 10_000;

    /**
     * @param  int                   $gross    recognized revenue in minor units
     * @param  int                   $shareBps instructor share in basis points, 0...10000
     * @return array{0: int, 1: int} [instructorPool, platformCut]; the two always sum to $gross
     */
    public static function split(int $gross, int $shareBps): array
    {
        if ($gross < 0) {
            throw new InvalidArgumentException("Gross must not be negative, got {$gross}.");
        }

        if ($shareBps < 0 || $shareBps > self::BPS_DENOMINATOR) {
            throw new InvalidArgumentException('Share must be between 0 and '.self::BPS_DENOMINATOR." basis points, got {$shareBps}.");
        }

        $instructorPool = intdiv($gross * $shareBps, self::BPS_DENOMINATOR);

        return [$instructorPool, $gross - $instructorPool];
    }
}
