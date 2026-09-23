<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when two amounts in different currencies are combined or compared.
 */
final class CurrencyMismatchException extends InvalidArgumentException
{
    public static function between(string $left, string $right): self
    {
        return new self("Cannot operate on {$left} and {$right}: currencies must match.");
    }
}
