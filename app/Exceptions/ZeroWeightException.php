<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when every weight handed to the allocator is zero.
 *
 * There is no defensible proportion to split by, and the allocator does not get
 * to decide what that means — the caller does (D-3, F05).
 */
final class ZeroWeightException extends InvalidArgumentException
{
    public static function forEmptyDenominator(): self
    {
        return new self('Cannot allocate across weights that sum to zero; the caller must decide what zero engagement means.');
    }
}
