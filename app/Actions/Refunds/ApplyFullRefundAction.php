<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\DTOs\Refunds\IssueRefundData;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\RefundMismatchException;
use App\Services\AccrualService;
use App\Services\EarningAllocationService;
use App\Services\LedgerService;
use App\Services\RefundService;
use App\Services\SubscriptionService;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;
use App\Support\Refunds\ClawbackPlan;
use App\Support\Refunds\RefundOutcome;
use App\Support\Refunds\RefundPlan;
use App\Support\Refunds\SubscriptionForRefund;

/**
 * Gives back the whole price, including time that was delivered and earned
 * (F09, D-6, D-7).
 *
 * The rare path, and the only one that ever takes money off an instructor. It
 * exists for chargebacks and goodwill gestures rather than for cancellations —
 * a student who simply leaves gets a pro-rata refund, which costs nobody
 * anything.
 *
 * Two movements, one transaction:
 *
 * - the unearned half discharges `deferred_revenue`, exactly as a pro-rata
 *   refund would;
 * - the earned half claws back every allocation and the platform's own cut,
 *   in **one** posting whose legs are aggregated per account.
 *
 * **Exact reversal, no re-rounding.** Every clawback is an allocation's own
 * recorded amount. Recomputing the shares would run the largest-remainder split
 * again and could land a piastre elsewhere, inventing or destroying money the
 * ledger has already committed to.
 *
 * Where the money comes from is the hold's whole purpose (D-6): earnings still
 * inside their window cost the instructor nothing, and only released ones come
 * out of `available`, where they may go negative and carry forward (D-7).
 */
final class ApplyFullRefundAction
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private AccrualService $accrual,
        private EarningAllocationService $allocations,
        private RefundService $refunds,
        private LedgerService $ledger,
    ) {}

    public function __invoke(IssueRefundData $data, SubscriptionForRefund $subscription, RefundPlan $plan): RefundOutcome
    {
        if (! $this->subscriptions->markRefunded($subscription->id, $data->recordedAt)) {
            return RefundOutcome::replayOf($data->type);
        }

        $currency = $subscription->price->currency;

        /** Step 1 — nothing more will be earned from this term. */
        $cancelled = $this->accrual->cancelScheduled($plan->cancelledPeriodIds);

        /** Step 2 — what was already earned, and by whom. */
        $recognized = $this->accrual->recognizedPeriodsFor($subscription->id);

        $clawback = ClawbackPlan::forAllocations(
            $this->allocations->linesForPeriods($recognized['ids']),
            $recognized['platform_minor'],
        );

        $unearned = $plan->unearnedMinor();
        $owed = $this->ledger->deferredRevenueOwedFor([$subscription->id])[$subscription->id] ?? 0;

        if ($unearned !== $owed) {
            throw RefundMismatchException::unearned($subscription->id, $unearned, $owed, $currency);
        }

        /**
         * Step 3 — the two halves have to add up to what the student paid.
         *
         * Checked before anything is posted, because a full refund that does
         * not equal the price is a bug in the split, the clawback or the
         * schedule, and none of those should be allowed to reach the ledger.
         */
        $total = $unearned + $clawback->totalMinor();

        if ($total !== $subscription->price->minor) {
            throw RefundMismatchException::total($subscription->id, $total, $subscription->price->minor, $currency);
        }

        $refundId = $this->refunds->record(
            $subscription->id,
            $subscription->paymentId,
            $data->type,
            $total,
            $currency,
            $data->effectiveAt,
            $data->externalRef,
            $data->reason,
        );

        $this->postUnearned($refundId, $subscription->id, $unearned, $currency);
        $this->postClawback($refundId, $clawback, $currency, $data);

        return RefundOutcome::of(
            $data->type,
            $total,
            $unearned,
            clawedBackMinor: $clawback->instructorTotalMinor,
            platformClawedBackMinor: $clawback->platformMinor,
            periodsCancelled: $cancelled,
            truncatedAPeriod: false,
            clawedBackByInstructor: $this->perInstructor($clawback),
            applied: true,
        );
    }

    private function postUnearned(int $refundId, int $subscriptionId, int $unearned, string $currency): void
    {
        if ($unearned === 0) {
            return;
        }

        $amount = Money::of($unearned, $currency);

        $this->ledger->post(LedgerTransaction::of(
            LedgerEntryType::REFUND_UNEARNED,
            'refund',
            $refundId,
            LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, $subscriptionId, $amount),
            LedgerLeg::credit(LedgerAccountType::PLATFORM_CASH, 0, $amount),
        ));
    }

    /**
     * One posting for the whole clawback, with the allocations marked reversed
     * in the same transaction.
     *
     * Legs are aggregated per instructor because a transaction may not touch
     * one account twice — an instructor who earned from six periods of this
     * term gets one leg, not six. The platform's cut is a single leg of its
     * own, and cash is the other side of all of it.
     */
    private function postClawback(int $refundId, ClawbackPlan $clawback, string $currency, IssueRefundData $data): void
    {
        if ($clawback->isEmpty()) {
            return;
        }

        $this->allocations->markClawedBack($clawback->allocationIds, $data->recordedAt);

        $legs = [];
        $deltas = [];

        /** Ascending instructor id — the lock order, and the order deltas are applied in. */
        foreach ($clawback->instructorIds() as $instructorId) {
            $legs[] = LedgerLeg::debit(
                LedgerAccountType::INSTRUCTOR_PAYABLE,
                $instructorId,
                Money::of($clawback->amountFor($instructorId), $currency),
            );

            $deltas[] = BalanceDelta::clawedBack(
                $instructorId,
                fromHeldMinor: $clawback->fromHeldByInstructor[$instructorId] ?? 0,
                fromAvailableMinor: $clawback->fromAvailableByInstructor[$instructorId] ?? 0,
            );
        }

        if ($clawback->platformMinor > 0) {
            $legs[] = LedgerLeg::debit(
                LedgerAccountType::PLATFORM_REVENUE,
                0,
                Money::of($clawback->platformMinor, $currency),
            );
        }

        $legs[] = LedgerLeg::credit(
            LedgerAccountType::PLATFORM_CASH,
            0,
            Money::of($clawback->totalMinor(), $currency),
        );

        $this->ledger->post(
            LedgerTransaction::of(LedgerEntryType::REFUND_CLAWBACK, 'refund', $refundId, ...$legs),
            ...$deltas,
        );
    }

    /**
     * @return array<int, int>
     */
    private function perInstructor(ClawbackPlan $clawback): array
    {
        $totals = [];

        foreach ($clawback->instructorIds() as $instructorId) {
            $totals[$instructorId] = $clawback->amountFor($instructorId);
        }

        return $totals;
    }
}
