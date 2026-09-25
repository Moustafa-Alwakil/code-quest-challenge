<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Services\LedgerService;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The postings F04-F09 will make, built by hand so F03 can be tested on its own.
 *
 * Each one is a real, balanced transaction with the account keying the ledger
 * actually uses — a test that posts made-up legs proves nothing about the money.
 */
final class LedgerPostings
{
    public const CURRENCY = 'EGP';

    /**
     * Posting always shares a transaction with the business state change it
     * records, so every test goes through this rather than calling the service
     * bare (F03 rule 1).
     */
    public static function post(LedgerTransaction $transaction, BalanceDelta ...$deltas): bool
    {
        return DB::transaction(
            fn (): bool => app(LedgerService::class)->post($transaction, ...$deltas),
        );
    }

    /**
     * A period is recognized (F05): deferred revenue becomes platform revenue
     * plus what the instructor has earned.
     */
    public static function recognition(int $periodId, int $subscriptionId, int $instructorId, int $grossMinor, int $instructorMinor): LedgerTransaction
    {
        return LedgerTransaction::of(
            LedgerEntryType::PERIOD_RECOGNIZED,
            'accrual_period',
            $periodId,
            LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, $subscriptionId, Money::of($grossMinor, self::CURRENCY)),
            LedgerLeg::credit(LedgerAccountType::PLATFORM_REVENUE, 0, Money::of($grossMinor - $instructorMinor, self::CURRENCY)),
            LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructorId, Money::of($instructorMinor, self::CURRENCY)),
        );
    }

    /**
     * A full refund claws recognized revenue back off the instructor (F09).
     */
    public static function clawback(int $refundId, int $instructorId, int $amountMinor): LedgerTransaction
    {
        return LedgerTransaction::of(
            LedgerEntryType::REFUND_CLAWBACK,
            'refund',
            $refundId,
            LedgerLeg::debit(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructorId, Money::of($amountMinor, self::CURRENCY)),
            LedgerLeg::credit(LedgerAccountType::PLATFORM_REVENUE, 0, Money::of($amountMinor, self::CURRENCY)),
        );
    }

    /**
     * A payout run reserves an instructor's available balance (F06).
     */
    public static function reservation(int $payoutItemId, int $instructorId, int $amountMinor): LedgerTransaction
    {
        return LedgerTransaction::of(
            LedgerEntryType::PAYOUT_RESERVED,
            'payout_item',
            $payoutItemId,
            LedgerLeg::debit(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructorId, Money::of($amountMinor, self::CURRENCY)),
            LedgerLeg::credit(LedgerAccountType::PROVIDER_IN_TRANSIT, $instructorId, Money::of($amountMinor, self::CURRENCY)),
        );
    }

    /**
     * The provider confirmed the transfer (F07).
     */
    public static function settlement(int $payoutItemId, int $instructorId, int $amountMinor): LedgerTransaction
    {
        return LedgerTransaction::of(
            LedgerEntryType::PAYOUT_SETTLED,
            'payout_item',
            $payoutItemId,
            LedgerLeg::debit(LedgerAccountType::PROVIDER_IN_TRANSIT, $instructorId, Money::of($amountMinor, self::CURRENCY)),
            LedgerLeg::credit(LedgerAccountType::PLATFORM_CASH, 0, Money::of($amountMinor, self::CURRENCY)),
        );
    }

    /**
     * The provider definitively failed, so the reservation comes back (F07, F08).
     */
    public static function reversal(int $payoutItemId, int $instructorId, int $amountMinor): LedgerTransaction
    {
        return LedgerTransaction::of(
            LedgerEntryType::PAYOUT_REVERSED,
            'payout_item',
            $payoutItemId,
            LedgerLeg::debit(LedgerAccountType::PROVIDER_IN_TRANSIT, $instructorId, Money::of($amountMinor, self::CURRENCY)),
            LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructorId, Money::of($amountMinor, self::CURRENCY)),
        );
    }

    /**
     * A student paid: cash in, an equal liability to deliver the term (F04).
     *
     * No instructor leg, so no balance delta — the posting that proves a
     * transaction can legitimately move no instructor's snapshot at all.
     */
    public static function payment(int $paymentId, int $subscriptionId, int $amountMinor): LedgerTransaction
    {
        return LedgerTransaction::of(
            LedgerEntryType::PAYMENT_RECEIVED,
            'payment',
            $paymentId,
            LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, Money::of($amountMinor, self::CURRENCY)),
            LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, $subscriptionId, Money::of($amountMinor, self::CURRENCY)),
        );
    }

    /**
     * What a recognition does to the snapshot while no hold exists.
     *
     * F05 holds the allocation for `hold_days` and `available` only moves on
     * release (R2, D-6). F03 has no `earning_allocations` table yet, so the
     * recognized amount is recognized and released in the same breath — which
     * is also what `ledger:verify` expects while `held` recomputes to 0.
     */
    public static function recognizedAndReleased(int $instructorId, int $amountMinor): BalanceDelta
    {
        return BalanceDelta::recognized($instructorId, $amountMinor)
            ->plus(BalanceDelta::released($instructorId, $amountMinor));
    }
}
