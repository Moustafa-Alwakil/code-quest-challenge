<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Services\PayoutItemService;
use Illuminate\Support\Facades\DB;

/**
 * Parks an item for a human, with its money still reserved (F07).
 *
 * Reached when the retries are exhausted or something threw that we cannot
 * interpret — never from a provider answer, which is always actionable one way
 * or the other.
 *
 * **It deliberately does not move money.** Returning the balance to `available`
 * would be claiming the transfer did not happen, and an exhausted retry
 * establishes no such thing; the reserved amount stays where it is until
 * someone reads the audit trail. Nor does it go back to `reserved`, which would
 * make the item dispatchable again with no new information.
 *
 * Takes scalars rather than a DTO because it is an internal action invoked by a
 * job's `failed()` handler, not an entry point translating an outside shape.
 */
final class FlagPayoutItemForReviewAction
{
    public function __construct(
        private PayoutItemService $payoutItems,
    ) {}

    /**
     * @return bool true when this call parked the item; false when it had already resolved
     */
    public function __invoke(int $payoutItemId, string $reason): bool
    {
        return DB::transaction(fn (): bool => $this->payoutItems->markNeedsReview($payoutItemId, $reason));
    }
}
