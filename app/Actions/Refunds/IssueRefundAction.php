<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\DTOs\Refunds\IssueRefundData;
use App\Enums\RefundType;
use App\Services\AccrualService;
use App\Services\EarningAllocationService;
use App\Services\RefundService;
use App\Services\SubscriptionService;
use App\Support\Refunds\ClawbackPlan;
use App\Support\Refunds\RefundOutcome;
use App\Support\Refunds\RefundPlan;
use App\Support\Refunds\SubscriptionForRefund;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single entry point for recording a refund (F09, R25).
 *
 * The gateway has already given the money back; this decides what that means
 * for the periods, the ledger and the instructors, and applies it exactly once.
 *
 * **The preview and the outcome are one calculation.** `--dry-run` builds the
 * same `RefundPlan` from the same periods and prints what it says; the real run
 * builds it inside the transaction and executes it. They cannot drift, because
 * there is nothing to drift from — the plan is a pure function of the term.
 *
 * Idempotency has the same two layers as `SubscribeStudentAction`: a sequential
 * replay is caught by the lookup and writes nothing, while a concurrent
 * duplicate gets past that — it cannot see the other transaction's uncommitted
 * row — and dies on UNIQUE `subscription_id`, taking every period it had begun
 * cancelling back out with it.
 */
final class IssueRefundAction
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private AccrualService $accrual,
        private EarningAllocationService $allocations,
        private RefundService $refunds,
        private ApplyProrataRefundAction $applyProrataRefund,
        private ApplyFullRefundAction $applyFullRefund,
    ) {}

    /**
     * @throws InvalidArgumentException when the subscription does not exist or was never paid for
     */
    public function __invoke(IssueRefundData $data): RefundOutcome
    {
        if ($data->dryRun) {
            return $this->preview($data);
        }

        return DB::transaction(function () use ($data): RefundOutcome {
            /** Locked first, and before anything is read: one lock order everywhere. */
            $subscription = $this->subscriptions->lockForRefund($data->subscriptionId);

            if (! $subscription instanceof SubscriptionForRefund) {
                throw new InvalidArgumentException(
                    "Subscription {$data->subscriptionId} has no recorded payment, so there is nothing to refund."
                );
            }

            /** A replay: the term is already refunded, and writes nothing. */
            if ($subscription->isAlreadyRefunded()) {
                return RefundOutcome::replayOf($data->type);
            }

            $plan = $this->planFor($data, $subscription);

            return $data->type === RefundType::FULL
                ? ($this->applyFullRefund)($data, $subscription, $plan)
                : ($this->applyProrataRefund)($data, $subscription, $plan);
        });
    }

    /**
     * What this refund would do, read without a lock and written nowhere.
     *
     * No transaction and no `FOR UPDATE`: a preview that took row locks would
     * block a concurrent recognition for as long as someone stared at the
     * output. The numbers are a snapshot, and the real run recomputes them
     * under the lock before acting on them.
     */
    private function preview(IssueRefundData $data): RefundOutcome
    {
        $subscription = $this->subscriptions->findForRefund($data->subscriptionId);

        if (! $subscription instanceof SubscriptionForRefund) {
            throw new InvalidArgumentException(
                "Subscription {$data->subscriptionId} has no recorded payment, so there is nothing to refund."
            );
        }

        if ($subscription->isAlreadyRefunded() || $this->refunds->idForSubscription($data->subscriptionId) !== null) {
            return RefundOutcome::replayOf($data->type);
        }

        $plan = $this->planFor($data, $subscription);
        $unearned = $plan->unearnedMinor();

        if ($data->type !== RefundType::FULL) {
            return RefundOutcome::of(
                $data->type,
                $unearned,
                $unearned,
                0,
                0,
                count($plan->cancelledPeriodIds),
                $plan->truncatesAPeriod(),
                [],
                applied: false,
            );
        }

        $recognized = $this->accrual->recognizedPeriodsFor($subscription->id);

        $clawback = ClawbackPlan::forAllocations(
            $this->allocations->linesForPeriods($recognized['ids']),
            $recognized['platform_minor'],
        );

        $perInstructor = [];

        foreach ($clawback->instructorIds() as $instructorId) {
            $perInstructor[$instructorId] = $clawback->amountFor($instructorId);
        }

        return RefundOutcome::of(
            $data->type,
            $unearned + $clawback->totalMinor(),
            $unearned,
            $clawback->instructorTotalMinor,
            $clawback->platformMinor,
            count($plan->cancelledPeriodIds),
            false,
            $perInstructor,
            applied: false,
        );
    }

    private function planFor(IssueRefundData $data, SubscriptionForRefund $subscription): RefundPlan
    {
        $periods = $this->accrual->periodLinesFor($subscription->id);

        return $data->type === RefundType::FULL
            ? RefundPlan::forFull($periods, $data->effectiveAt)
            : RefundPlan::forProrata($periods, $data->effectiveAt);
    }
}
