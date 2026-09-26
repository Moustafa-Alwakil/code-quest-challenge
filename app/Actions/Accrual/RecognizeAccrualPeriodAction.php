<?php

declare(strict_types=1);

namespace App\Actions\Accrual;

use App\DTOs\Accrual\RecognizePeriodData;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Services\AccrualService;
use App\Services\EarningAllocationService;
use App\Services\EngagementService;
use App\Services\LedgerService;
use App\Support\Accrual\PeriodForRecognition;
use App\Support\Accrual\RecognitionOutcome;
use App\Support\Allocator;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;
use App\Support\RevenueSplit;
use Illuminate\Support\Facades\DB;

/**
 * Turns one closed accrual period into platform revenue plus instructor
 * earnings, divided by engagement (D-1, D-2, D-3, F05).
 *
 * **One transaction, no intermediate status (R3).** The compare-and-set that
 * claims the period and every write that follows commit together, so a crash
 * anywhere takes the status back to `scheduled` with them and the next run
 * picks the period up. A `recognizing` state would be stranded by exactly the
 * crash it was invented to survive.
 *
 * That makes the whole action safe to run twice, concurrently, or after a
 * failure, without a lock: the CAS decides who recognizes, and the loser writes
 * nothing at all.
 *
 * F09 invokes this for a period it has just truncated — the used days of a
 * refunded term are earned from that period's engagement like any other.
 */
final class RecognizeAccrualPeriodAction
{
    public function __construct(
        private AccrualService $accrual,
        private EngagementService $engagement,
        private EarningAllocationService $allocations,
        private LedgerService $ledger,
    ) {}

    public function __invoke(RecognizePeriodData $data): RecognitionOutcome
    {
        return DB::transaction(function () use ($data): RecognitionOutcome {
            /**
             * Step 1 — claim the period. Affected 0 means it is already
             * recognized, or a refund cancelled it; either way this run is done
             * with it and has written nothing.
             */
            if (! $this->accrual->markRecognized($data->periodId, $data->recognizedAt)) {
                return RecognitionOutcome::skipped($data->periodId);
            }

            $period = $this->accrual->findForRecognition($data->periodId);

            if (! $period instanceof PeriodForRecognition) {
                return RecognitionOutcome::skipped($data->periodId);
            }

            /** Step 2 — the weights, read at recognition time and never re-read (F02). */
            $units = $this->engagement->unitsFor($period->subscriptionId, $period->periodStart);

            /**
             * Steps 3 and 4 — the split, then the allocation.
             *
             * Zero engagement is decided here and not inside the allocator
             * (D-3): with no weights there is no defensible proportion, so the
             * pool is zero and the platform retains the whole gross. The
             * allocator would throw on an empty denominator, which is correct
             * of it — the policy call is the caller's.
             */
            $grossMinor = $period->gross->minor;

            [$poolMinor, $platformMinor] = $units === []
                ? [0, $grossMinor]
                : RevenueSplit::split($grossMinor, $data->instructorShareBps);

            $shares = $poolMinor === 0
                ? []
                : array_filter(Allocator::largestRemainder($poolMinor, $units));

            /**
             * Step 5 — the allocation rows, which *are* the hold (R2).
             *
             * A share that rounded to nothing gets no row: an allocation of
             * zero would claim the instructor earned something and would sit in
             * the maturation sweep forever releasing nothing.
             */
            $allocated = $this->allocations->insertFor(
                $period->periodId,
                $period->gross->currency,
                $period->availableAt($data->holdDays),
                $shares,
                $units,
            );

            /** Step 6 — the posting, and the snapshot deltas that go with it. */
            $this->ledger->post(
                $this->postingFor($period, $platformMinor, $shares),
                ...$this->deltasFor($shares),
            );

            /** Step 7 — how the gross was divided, for invariant I6 to check. */
            $this->accrual->storeSplit($period->periodId, $poolMinor, $platformMinor);

            return RecognitionOutcome::recognized($period->periodId, $poolMinor, $platformMinor, $allocated);
        });
    }

    /**
     * The `period_recognized` transaction: the liability the student's payment
     * created is discharged into revenue and what each instructor is owed.
     *
     * The platform leg is written even when it is zero — a 10 000 bps share
     * leaves the platform nothing, and a transaction still needs two legs. An
     * instructor leg of zero, by contrast, never exists: zero shares were
     * dropped before they reached here, so no two legs can collide on one
     * account and the transaction's own duplicate-account rule stays satisfied.
     *
     * @param array<int, int> $shares instructor id => amount in minor units, all non-zero
     */
    private function postingFor(PeriodForRecognition $period, int $platformMinor, array $shares): LedgerTransaction
    {
        $currency = $period->gross->currency;

        $legs = [
            LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, $period->subscriptionId, $period->gross),
            LedgerLeg::credit(LedgerAccountType::PLATFORM_REVENUE, 0, Money::of($platformMinor, $currency)),
        ];

        foreach ($shares as $instructorId => $amountMinor) {
            $legs[] = LedgerLeg::credit(
                LedgerAccountType::INSTRUCTOR_PAYABLE,
                $instructorId,
                Money::of($amountMinor, $currency),
            );
        }

        return LedgerTransaction::of(
            LedgerEntryType::PERIOD_RECOGNIZED,
            'accrual_period',
            $period->periodId,
            ...$legs,
        );
    }

    /**
     * Earned and held in the same movement (D-6): the money is owed from this
     * instant, but is not payable until `available_at`. The hold is released
     * by its own sweep, which posts no ledger entry because there is none to
     * post (R2).
     *
     * Ascending instructor id, which is the order the snapshot rows must be
     * locked in to keep two concurrent multi-instructor postings from
     * deadlocking — `EngagementService` already returns the weights that way.
     *
     * @param  array<int, int>    $shares
     * @return list<BalanceDelta>
     */
    private function deltasFor(array $shares): array
    {
        $deltas = [];

        foreach ($shares as $instructorId => $amountMinor) {
            $deltas[] = BalanceDelta::recognized($instructorId, $amountMinor);
        }

        return $deltas;
    }
}
