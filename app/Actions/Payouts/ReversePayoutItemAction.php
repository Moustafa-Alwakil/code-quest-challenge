<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutItemStatus;
use App\Services\LedgerService;
use App\Services\PayoutItemService;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;
use App\Support\Payouts\PayoutItemSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The transfer definitively failed: the reservation comes back (F07, F08).
 *
 * "Definitively" is load-bearing. This action is only ever reached from a
 * provider answer of `failed` — never from a timeout, an exhausted retry or an
 * exception, because none of those establish that the money did not move.
 * Returning a balance to `available` on a guess is how an instructor gets paid
 * twice.
 *
 * Shared with F08 for the same reason as its settling counterpart: one
 * implementation of each money movement.
 */
final class ReversePayoutItemAction
{
    public function __construct(
        private PayoutItemService $payoutItems,
        private LedgerService $ledger,
    ) {}

    /**
     * @return bool true when this call reversed the item; false when it was already terminal
     */
    public function __invoke(PayoutItemSnapshot $item, string $failureCode, CarbonImmutable $settledAt): bool
    {
        return DB::transaction(function () use ($item, $failureCode, $settledAt): bool {
            $moved = $this->payoutItems->settle(
                $item->id,
                PayoutItemStatus::FAILED,
                $settledAt,
                null,
                $failureCode,
            );

            if (! $moved) {
                return false;
            }

            $amount = Money::of($item->amountMinor, $item->currency);

            /**
             * The exact mirror of the reservation: what was in transit is owed
             * again. A new entry type rather than a deleted one — the ledger is
             * append-only, and "we tried and it failed" is part of the history
             * (D-9).
             */
            $this->ledger->post(
                LedgerTransaction::of(
                    LedgerEntryType::PAYOUT_REVERSED,
                    'payout_item',
                    $item->id,
                    LedgerLeg::debit(LedgerAccountType::PROVIDER_IN_TRANSIT, $item->instructorId, $amount),
                    LedgerLeg::credit(LedgerAccountType::INSTRUCTOR_PAYABLE, $item->instructorId, $amount),
                ),
                BalanceDelta::reversed($item->instructorId, $item->amountMinor),
            );

            return true;
        });
    }
}
