<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Accrual\RecognizeAccrualPeriodAction;
use App\DTOs\Accrual\RecognizePeriodData;
use App\DTOs\Refunds\IssueRefundData;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\RefundMismatchException;
use App\Services\AccrualService;
use App\Services\LedgerService;
use App\Services\RefundService;
use App\Services\SubscriptionService;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;
use App\Support\Refunds\PeriodTruncation;
use App\Support\Refunds\RefundOutcome;
use App\Support\Refunds\RefundPlan;
use App\Support\Refunds\SubscriptionForRefund;

/**
 * Gives back the time a student did not use — and costs no instructor a
 * piastre (F09, D-1).
 *
 * This is the sentence the whole accrual design pays for: **a student
 * cancelling does not cost any instructor anything, because nobody was ever
 * credited for the months the student didn't use.** Under D-1 the unused time
 * is exactly the set of periods nobody has recognized, so refunding it touches
 * `deferred_revenue` and `platform_cash` and nothing else. No clawback, no
 * negative balance, no instructor notified that money has been taken back.
 *
 * The one period that needs care is the one the refund lands inside. Its used
 * days were delivered, so they are recognized like any other period — through
 * F05's action, against that period's real engagement — and only the remainder
 * is refunded.
 *
 * One transaction. Locks in the order used everywhere: the subscription first,
 * then periods, then instructor balances ascending.
 */
final class ApplyProrataRefundAction
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private AccrualService $accrual,
        private RefundService $refunds,
        private LedgerService $ledger,
        private RecognizeAccrualPeriodAction $recognizeAccrualPeriod,
    ) {}

    public function __invoke(IssueRefundData $data, SubscriptionForRefund $subscription, RefundPlan $plan): RefundOutcome
    {
        /** Step 1 — the term is closed to further recognition. */
        if (! $this->subscriptions->markRefunded($subscription->id, $data->recordedAt)) {
            return RefundOutcome::replayOf($data->type);
        }

        /**
         * Step 2 — the period the refund lands inside keeps its used days and
         * is recognized now. Recognizing *before* cancelling the rest matters:
         * it moves that period's gross out of `deferred_revenue`, so the
         * balance step 4 checks against is the unearned remainder exactly.
         */
        if ($plan->truncation instanceof PeriodTruncation) {
            $this->truncateAndRecognize($plan->truncation, $data, $subscription->price->currency);
        }

        /** Step 3 — everything the student never reached. */
        $cancelled = $this->accrual->cancelScheduled($plan->cancelledPeriodIds);

        /**
         * Step 4 — two independent numbers, which must agree.
         *
         * The periods say what is unearned; the ledger has been carrying the
         * same figure as a liability since the payment was recorded. They are
         * derived from entirely different things, so agreement is real evidence
         * — and disagreement means something upstream is wrong and this refund
         * must not be written.
         */
        $unearned = $plan->unearnedMinor();
        $owed = $this->ledger->deferredRevenueOwedFor([$subscription->id])[$subscription->id] ?? 0;

        if ($unearned !== $owed) {
            throw RefundMismatchException::unearned($subscription->id, $unearned, $owed, $subscription->price->currency);
        }

        /** Step 5 — the refund itself, and the liability it discharges. */
        $refundId = $this->refunds->record(
            $subscription->id,
            $subscription->paymentId,
            $data->type,
            $unearned,
            $subscription->price->currency,
            $data->effectiveAt,
            $data->externalRef,
            $data->reason,
        );

        if ($unearned > 0) {
            $amount = Money::of($unearned, $subscription->price->currency);

            $this->ledger->post(LedgerTransaction::of(
                LedgerEntryType::REFUND_UNEARNED,
                'refund',
                $refundId,
                LedgerLeg::debit(LedgerAccountType::DEFERRED_REVENUE, $subscription->id, $amount),
                LedgerLeg::credit(LedgerAccountType::PLATFORM_CASH, 0, $amount),
            ));
        }

        return RefundOutcome::of(
            $data->type,
            $unearned,
            $unearned,
            clawedBackMinor: 0,
            platformClawedBackMinor: 0,
            periodsCancelled: $cancelled,
            truncatedAPeriod: $plan->truncatesAPeriod(),
            clawedBackByInstructor: [],
            applied: true,
        );
    }

    /**
     * Shrinks the straddled period to its delivered days, then earns them.
     *
     * The recognition is F05's, not a copy of it: the used days are earned from
     * that period's engagement like any other, so the same split, the same
     * hold and the same postings apply. A refund is not a licence to invent
     * revenue by a different route.
     *
     * If the truncation CAS fails, `ledger:accrue` recognized the period
     * between the plan and this write. The mismatch check at step 4 then fails
     * and the whole transaction rolls back, which is the correct outcome: the
     * plan was computed against a term that no longer exists.
     */
    private function truncateAndRecognize(PeriodTruncation $truncation, IssueRefundData $data, string $currency): void
    {
        $truncated = $this->accrual->truncatePeriod(
            $truncation->periodId,
            $truncation->newPeriodEnd,
            $truncation->usedDays,
            $truncation->usedMinor,
        );

        if (! $truncated) {
            return;
        }

        ($this->recognizeAccrualPeriod)(RecognizePeriodData::forPeriod(
            $truncation->periodId,
            $data->instructorShareBps,
            $data->holdDays,
            $currency,
            $data->zeroEngagementPolicy,
            $data->recordedAt,
        ));
    }
}
