<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\DTOs\Payouts\ResolvePayoutItemData;
use App\Enums\PayoutAttemptOperation;
use App\Enums\PayoutItemStatus;
use App\Services\Contracts\PayoutItemServiceContract;
use App\Support\Payouts\PayoutItemSnapshot;
use InvalidArgumentException;

/**
 * Lets a person resolve a payout the system refused to guess about (F08).
 *
 * `needs_review` is where money goes when nothing has *established* what
 * happened — retries exhausted against an unreachable provider, or a full day
 * of the provider never deciding. The amount stays in `provider_in_transit`:
 * not paid, not returned, and deliberately not moved by any automated path,
 * because both directions are wrong without evidence.
 *
 * This is the evidence arriving. Someone read the audit trail, phoned the
 * provider or checked a statement, and now knows. The money then moves through
 * **the same** settle and reverse actions every other outcome uses — there is
 * no manual path into the ledger, only a manual answer to the question those
 * actions were already asking.
 *
 * What makes that acceptable is the `payout_attempts` row it writes: operation
 * `manual`, with the operator's reason, sitting in the same list as the
 * provider's own answers. Money moving on a person's word is fine; money moving
 * on a person's word with no record of who or why is not.
 */
final class ResolvePayoutItemAction
{
    public function __construct(
        private PayoutItemServiceContract $payoutItems,
        private SettlePayoutItemAction $settlePayoutItem,
        private ReversePayoutItemAction $reversePayoutItem,
    ) {}

    /**
     * @return bool true when this call resolved the item
     *
     * @throws InvalidArgumentException when the item does not exist, or is not under review
     */
    public function __invoke(ResolvePayoutItemData $data): bool
    {
        $item = $this->payoutItems->find($data->payoutItemId);

        if (! $item instanceof PayoutItemSnapshot) {
            throw new InvalidArgumentException("Payout item {$data->payoutItemId} does not exist.");
        }

        /**
         * Only items parked for a human. Anything else is either still moving
         * on its own — in which case a person overriding it would be racing the
         * reconciliation sweep — or already terminal, in which case there is
         * nothing left to decide.
         */
        if ($item->status !== PayoutItemStatus::NEEDS_REVIEW) {
            throw new InvalidArgumentException(
                "Payout item {$data->payoutItemId} is {$item->status->value}, not needs_review. "
                .'Only items awaiting a human decision can be resolved by hand.'
            );
        }

        /**
         * Recorded before the money moves, so a failure between the two leaves
         * the reason on file rather than an unexplained settlement.
         */
        $this->payoutItems->recordAttempt(
            $item->id,
            PayoutAttemptOperation::MANUAL,
            "operator resolved as {$data->outcome->value}",
            $data->reason,
            $data->outcome->value,
            durationMs: 0,
        );

        return $data->outcome === PayoutItemStatus::SUCCEEDED
            ? ($this->settlePayoutItem)(
                $item,
                $data->providerReference,
                $data->resolvedAt,
                from: PayoutItemServiceContract::UNDER_REVIEW,
            )
            : ($this->reversePayoutItem)(
                $item,
                $data->reason,
                $data->resolvedAt,
                from: PayoutItemServiceContract::UNDER_REVIEW,
            );
    }
}
