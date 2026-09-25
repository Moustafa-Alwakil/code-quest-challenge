<?php

declare(strict_types=1);

namespace App\Support\Ledger;

use App\Enums\LedgerAccountType;
use App\Exceptions\InvalidLedgerTransactionException;
use App\Support\Money;

/**
 * One side of a ledger posting: an account, and a signed amount (F03).
 *
 * The sign convention — debit positive, credit negative — is expressed exactly
 * once, here, by the two named constructors. Callers state a positive magnitude
 * and say which way it goes; nothing downstream has to remember the rule.
 */
final readonly class LedgerLeg
{
    private function __construct(
        public LedgerAccountType $accountType,
        public int $accountId,
        public Money $amount,
    ) {}

    /**
     * Money into the account: positive.
     */
    public static function debit(LedgerAccountType $accountType, int $accountId, Money $amount): self
    {
        self::assertPositiveMagnitude($accountType, $accountId, $amount);

        return new self($accountType, $accountId, $amount);
    }

    /**
     * Money out of the account: the amount is negated here, so a credit is
     * never written as a negative number by a caller.
     */
    public static function credit(LedgerAccountType $accountType, int $accountId, Money $amount): self
    {
        self::assertPositiveMagnitude($accountType, $accountId, $amount);

        return new self($accountType, $accountId, $amount->negate());
    }

    public function isDebit(): bool
    {
        return $this->amount->isPositive();
    }

    public function isCredit(): bool
    {
        return $this->amount->isNegative();
    }

    /**
     * The `(account_type, account_id)` pair a transaction may only use once.
     */
    public function accountKey(): string
    {
        return $this->accountType->value.'#'.$this->accountId;
    }

    /**
     * A zero leg is allowed — an allocation can round to nothing — but a
     * negative magnitude means the caller has applied the sign itself, which
     * is exactly the ambiguity debit() and credit() exist to remove.
     */
    private static function assertPositiveMagnitude(LedgerAccountType $accountType, int $accountId, Money $amount): void
    {
        if ($amount->isNegative()) {
            throw InvalidLedgerTransactionException::negativeLegAmount($accountType->value, $accountId, $amount->minor);
        }
    }
}
