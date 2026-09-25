<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five accounts of the double-entry ledger (D-9, F03).
 *
 * Debits are positive and credits negative, so an account's *owed* balance —
 * liabilities are credit-normal — is the negated sum of its entries.
 *
 * Keying (R4, R5): singleton accounts use `account_id = 0`, `deferred_revenue`
 * is keyed per subscription and `instructor_payable` / `provider_in_transit`
 * per instructor. `account_id` is NOT NULL because MySQL unique indexes treat
 * NULLs as distinct, which would silently disable the idempotency key.
 */
enum LedgerAccountType: string
{
    case PLATFORM_CASH = 'platform_cash';
    case DEFERRED_REVENUE = 'deferred_revenue';
    case PLATFORM_REVENUE = 'platform_revenue';
    case INSTRUCTOR_PAYABLE = 'instructor_payable';
    case PROVIDER_IN_TRANSIT = 'provider_in_transit';

    /**
     * The accounts a balance recomputation has to read, derived from
     * isInstructorKeyed() so the set exists in exactly one place.
     *
     * Adding a sixth account that follows an instructor is then a one-line
     * change here, not a hunt through every query that recomputes a balance.
     *
     * @return list<self>
     */
    public static function instructorKeyed(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $accountType): bool => $accountType->isInstructorKeyed(),
        ));
    }

    /**
     * Whether the account has exactly one instance, and so is keyed with the
     * `account_id = 0` sentinel rather than a business row id.
     *
     * The single authority for that sentinel: LedgerTransaction validates
     * against it, so a mis-keyed leg can never reach the table.
     */
    public function isSingleton(): bool
    {
        return match ($this) {
            self::PLATFORM_CASH, self::PLATFORM_REVENUE => true,
            self::DEFERRED_REVENUE, self::INSTRUCTOR_PAYABLE, self::PROVIDER_IN_TRANSIT => false,
        };
    }

    /**
     * Whether the account is keyed by instructor id, and therefore contributes
     * to that instructor's balance snapshot.
     */
    public function isInstructorKeyed(): bool
    {
        return match ($this) {
            self::INSTRUCTOR_PAYABLE, self::PROVIDER_IN_TRANSIT => true,
            self::PLATFORM_CASH, self::PLATFORM_REVENUE, self::DEFERRED_REVENUE => false,
        };
    }
}
