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
 * The provider confirmed the transfer: reserved money becomes paid (F07).
 *
 * Shared with F08, deliberately — reconciliation settles an `unknown` item
 * through this same action, so there is exactly one implementation of "money
 * left the platform" and one place that could ever get it wrong.
 *
 * The CAS and the posting share a transaction, so an item can never be
 * `succeeded` without its ledger entries or vice versa. The CAS is also the
 * idempotency guard: an item already terminal affects zero rows, and this
 * returns false having written nothing — which is what a duplicate job
 * delivery and a late provider response both look like from here.
 */
final class SettlePayoutItemAction
{
    public function __construct(
        private PayoutItemService $payoutItems,
        private LedgerService $ledger,
    ) {}

    /**
     * @return bool true when this call settled the item; false when it was already settled
     */
    public function __invoke(PayoutItemSnapshot $item, ?string $providerReference, CarbonImmutable $settledAt): bool
    {
        return DB::transaction(function () use ($item, $providerReference, $settledAt): bool {
            $moved = $this->payoutItems->settle(
                $item->id,
                PayoutItemStatus::SUCCEEDED,
                $settledAt,
                $providerReference,
            );

            if (! $moved) {
                return false;
            }

            $amount = Money::of($item->amountMinor, $item->currency);

            /**
             * Money in transit becomes cash out of the platform. This is the
             * only entry type that closes a `provider_in_transit` balance
             * downward, which is what makes "reserved per instructor" provable
             * at any moment (R5).
             */
            $this->ledger->post(
                LedgerTransaction::of(
                    LedgerEntryType::PAYOUT_SETTLED,
                    'payout_item',
                    $item->id,
                    LedgerLeg::debit(LedgerAccountType::PROVIDER_IN_TRANSIT, $item->instructorId, $amount),
                    LedgerLeg::credit(LedgerAccountType::PLATFORM_CASH, 0, $amount),
                ),
                BalanceDelta::settled($item->instructorId, $item->amountMinor),
            );

            return true;
        });
    }
}
