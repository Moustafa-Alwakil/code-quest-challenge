<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\DTOs\Payouts\ReserveInstructorBalanceData;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Services\LedgerService;
use App\Services\PayoutRunService;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;
use App\Support\Payouts\ReservationOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Moves one instructor's available balance out of `available` and into
 * `reserved`, atomically, **before any provider call** (F06, D-10).
 *
 * This is the guarantee the whole payout side rests on. Once the money has
 * left `available`, a second run — same key or a new one — cannot see it to pay
 * again, whatever the queue, the workers or the cache are doing. The provider
 * has not been contacted at this point and will not be until the transaction
 * below has committed (PLAN §8.2).
 *
 * Two mechanisms, for two different scopes, and both are needed:
 *
 * - UNIQUE `(payout_run_id, instructor_id)` stops a *second invocation of one
 *   run* creating a second item;
 * - reservation itself stops a *later run with a new key* paying a balance that
 *   has already gone out.
 *
 * Neither substitutes for the other, and neither is a lock. The `FOR UPDATE`
 * below is a correctness guard of a third kind: it serializes this reservation
 * against a concurrent recognition or clawback for the same instructor, so the
 * amount reserved is a balance that actually existed at one instant.
 */
final class ReserveInstructorBalanceAction
{
    public function __construct(
        private PayoutRunService $payoutRuns,
        private LedgerService $ledger,
    ) {}

    public function __invoke(ReserveInstructorBalanceData $data): ReservationOutcome
    {
        return DB::transaction(function () use ($data): ReservationOutcome {
            $instructorId = $data->instructorId;

            /** Step 1 — lock the row, so the amount cannot move under us. */
            $available = $this->payoutRuns->lockAvailableBalance($instructorId);

            if ($available === null) {
                return ReservationOutcome::skipped($instructorId, ReservationOutcome::REASON_NO_SNAPSHOT);
            }

            /**
             * Step 2 — below the minimum, or negative, carries forward (D-7).
             * A negative balance is an instructor who owes the platform after a
             * clawback; it nets against their next earnings rather than being
             * chased, and it is never payable.
             */
            if ($available < $data->minimumAmountMinor || $available <= 0) {
                return ReservationOutcome::skipped($instructorId, ReservationOutcome::REASON_BELOW_MINIMUM);
            }

            /** Step 3 — the item. Affected 0 means this run already has one. */
            $item = $this->payoutRuns->createReservedItem($data->payoutRunId, $instructorId, $available, $data->currency);

            if ($item === null) {
                return ReservationOutcome::skipped($instructorId, ReservationOutcome::REASON_ALREADY_RESERVED);
            }

            [$itemId] = $item;

            $amount = Money::of($available, $data->currency);

            /**
             * Step 4 — what we owe the instructor becomes money in transit to
             * them. Keyed per instructor on both sides (R5), so "how much is
             * reserved for this instructor" is a question the ledger answers.
             */
            $this->ledger->post(
                LedgerTransaction::of(
                    LedgerEntryType::PAYOUT_RESERVED,
                    'payout_item',
                    $itemId,
                    LedgerLeg::debit(LedgerAccountType::INSTRUCTOR_PAYABLE, $instructorId, $amount),
                    LedgerLeg::credit(LedgerAccountType::PROVIDER_IN_TRANSIT, $instructorId, $amount),
                ),
                BalanceDelta::reserved($instructorId, $available),
            );

            $this->payoutRuns->addToTally($data->payoutRunId, $available);

            return ReservationOutcome::reserved($instructorId, $available, $itemId);
        });
    }
}
