<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a set of legs is not a valid double-entry transaction (F03).
 *
 * Every rule here is checked before a single row is written, so an unbalanced
 * or mis-keyed transaction never reaches the table at all.
 */
final class InvalidLedgerTransactionException extends InvalidArgumentException
{
    public static function tooFewLegs(int $legs): self
    {
        return new self("A ledger transaction needs at least 2 legs, got {$legs}.");
    }

    public static function unbalanced(int $sumMinor, string $currency): self
    {
        return new self("Ledger legs must sum to zero, got {$sumMinor} {$currency}.");
    }

    public static function duplicateAccount(string $accountType, int $accountId): self
    {
        return new self("Two legs post to {$accountType}#{$accountId}: net them into one leg, or the unique key would reject the second.");
    }

    public static function singletonAccountMustUseZero(string $accountType, int $accountId): self
    {
        return new self("{$accountType} is a singleton account and must be keyed with account_id 0, got {$accountId}.");
    }

    public static function accountRequiresId(string $accountType, int $accountId): self
    {
        return new self("{$accountType} is keyed by a business row and needs a positive account_id, got {$accountId}.");
    }

    public static function invalidReferenceId(int $referenceId): self
    {
        return new self("A ledger transaction needs a positive reference_id, got {$referenceId}; NULL and 0 would break the idempotency key.");
    }

    public static function blankReferenceType(): self
    {
        return new self('A ledger transaction needs a non-empty reference_type; it is part of the idempotency key.');
    }

    public static function negativeLegAmount(string $accountType, int $accountId, int $amountMinor): self
    {
        return new self("Leg amounts are stated as positive magnitudes, got {$amountMinor} for {$accountType}#{$accountId}: use debit() or credit() to carry the sign.");
    }
}
