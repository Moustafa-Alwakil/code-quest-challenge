<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a captured payment is not what the plan costs (F04).
 *
 * Raised before anything is written, so the transaction takes nothing back out
 * with it. The alternative — recording the payment and reconciling later — would
 * put a subscription on the books whose deferred revenue never matches its
 * schedule, and the ledger would be wrong for as long as it took to notice.
 */
final class PaymentMismatchException extends DomainException
{
    public static function amount(string $externalRef, int $expectedMinor, int $actualMinor, string $currency): self
    {
        return new self("Payment {$externalRef} is {$actualMinor} {$currency} but the plan costs {$expectedMinor} {$currency}; nothing was recorded.");
    }

    public static function currency(string $externalRef, string $expected, string $actual): self
    {
        return new self("Payment {$externalRef} is in {$actual} but the plan is priced in {$expected}; nothing was recorded.");
    }
}
