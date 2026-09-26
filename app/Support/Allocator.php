<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ZeroWeightException;
use InvalidArgumentException;
use OverflowException;

/**
 * Splits an integer total across integer weights so the parts sum to exactly
 * the total — the largest-remainder method with a deterministic tie-break (D-5).
 *
 * Three guarantees at once: the parts sum to the whole; no key is systematically
 * favoured; the result is byte-identical on every re-run, which is what makes
 * allocation both idempotent and testable.
 *
 * Integer operations only. There is no float division anywhere in this path.
 */
final class Allocator
{
    /**
     * Guards `total * weight` against signed 64-bit overflow.
     *
     * 10^12 minor units is EGP 10bn; 10^6 is a weight ceiling no engagement
     * rollup approaches. Their product, 10^18, sits below PHP_INT_MAX (~9.2x10^18).
     */
    private const MAX_TOTAL = 1_000_000_000_000;

    private const MAX_WEIGHT = 1_000_000;

    /**
     * The key type is carried through rather than flattened to `array-key`:
     * callers weight by instructor id or by period sequence and then look the
     * result up by that same id, so "every input key, in input order" is part
     * of the contract, not an implementation detail.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int> $weights
     * @return array<TKey, int> every input key, in input order
     *
     * @throws ZeroWeightException      when every weight is zero
     * @throws InvalidArgumentException on a negative total or weight
     * @throws OverflowException        when the guard bound is exceeded
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        self::assertValidTotal($total);

        $denominator = 0;

        foreach ($weights as $key => $weight) {
            self::assertValidWeight($key, $weight);
            $denominator += $weight;
        }

        if ($denominator === 0) {
            throw ZeroWeightException::forEmptyDenominator();
        }

        $bases = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $key => $weight) {
            $product = $total * $weight;
            $bases[$key] = intdiv($product, $denominator);
            $remainders[$key] = $product % $denominator;
            $allocated += $bases[$key];
        }

        $leftover = $total - $allocated;

        $order = array_keys($weights);
        usort($order, fn (int|string $a, int|string $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        foreach (array_slice($order, 0, $leftover) as $key) {
            $bases[$key]++;
        }

        return $bases;
    }

    private static function assertValidTotal(int $total): void
    {
        if ($total < 0) {
            throw new InvalidArgumentException("Total must not be negative, got {$total}.");
        }

        if ($total > self::MAX_TOTAL) {
            throw new OverflowException('Total '.$total.' exceeds the allocator guard of '.self::MAX_TOTAL.' minor units.');
        }
    }

    private static function assertValidWeight(int|string $key, mixed $weight): void
    {
        if (! is_int($weight)) {
            throw new InvalidArgumentException("Weight for key '{$key}' must be an integer, got ".get_debug_type($weight).'.');
        }

        if ($weight < 0) {
            throw new InvalidArgumentException("Weight for key '{$key}' must not be negative, got {$weight}.");
        }

        if ($weight > self::MAX_WEIGHT) {
            throw new OverflowException("Weight for key '{$key}' exceeds the allocator guard of ".self::MAX_WEIGHT.'.');
        }
    }
}
