<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Enums\PayoutAttemptOperation;
use App\Enums\PayoutItemStatus;
use App\Enums\TransferStatus;
use App\Jobs\ProcessPayoutItemJob;
use App\Services\PaymentProvider;
use App\Services\PayoutItemService;
use App\Support\Payouts\PayoutItemSnapshot;
use App\Support\Payouts\ReconciliationSchedule;
use App\Support\Payouts\TransferResult;
use Carbon\CarbonImmutable;

/**
 * Resolves one uncertain payout by **asking the provider, never by guessing**
 * (F08, D-8).
 *
 * The rule the whole feature turns on: `unknown` never becomes `failed` through
 * the passage of time. Time is not evidence. An item nobody can resolve goes to
 * a human with its money still frozen in `provider_in_transit` — it is never
 * auto-released to `available` and never auto-resent.
 *
 * `not_found` gets the same scepticism. A provider's status API can lag its
 * transfer API, so "no record" moments after a timeout may simply mean "not
 * visible yet". Inside the grace window it is rescheduled; outside it the item
 * goes back to `reserved` and is sent again **with the same key**, so the
 * provider's own dedup protects us if the status API was wrong.
 *
 * Settling and reversing go through F07's actions, so there is exactly one
 * implementation of each money movement whether a worker or this sweep
 * discovers the outcome.
 */
final class ReconcilePayoutItemAction
{
    public function __construct(
        private PayoutItemService $payoutItems,
        private PaymentProvider $provider,
        private SettlePayoutItemAction $settlePayoutItem,
        private ReversePayoutItemAction $reversePayoutItem,
        private FlagPayoutItemForReviewAction $flagPayoutItemForReview,
        private FinalizePayoutRunAction $finalizePayoutRun,
    ) {}

    public function __invoke(int $payoutItemId): PayoutItemStatus
    {
        $item = $this->payoutItems->find($payoutItemId);

        if ($item === null) {
            return PayoutItemStatus::NEEDS_REVIEW;
        }

        /** Nothing uncertain about it: already resolved, or already with a human. */
        if ($item->status->isTerminal() || $item->status === PayoutItemStatus::NEEDS_REVIEW) {
            return $item->status;
        }

        $status = $this->resolve($item);

        /**
         * The run's status is a function of its items, so it is recomputed
         * whenever one of them moves — that is how a run sitting in
         * `completed_with_pending` reaches `completed` once the last unknown
         * item resolves (R33).
         */
        ($this->finalizePayoutRun)($item->payoutRunId);

        return $status;
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function resolve(PayoutItemSnapshot $item): PayoutItemStatus
    {
        $startedAt = hrtime(true);
        $result = $this->provider->getStatus($item->idempotencyKey);
        $now = CarbonImmutable::now();

        $this->payoutItems->recordAttempt(
            $item->id,
            PayoutAttemptOperation::STATUS,
            "getStatus {$item->idempotencyKey}",
            $result->describe(),
            $result->status->value,
            self::elapsedMs($startedAt),
        );

        if ($result->status->isDefinitive()) {
            return $this->applyDefinitive($item, $result, $now);
        }

        if ($result->status === TransferStatus::NOT_FOUND && ! ReconciliationSchedule::isWithinNotFoundGrace($item->submittedAt, $now)) {
            return $this->resend($item);
        }

        return $this->waitOrEscalate($item, $result, $now);
    }

    /**
     * The provider finally answered. One of F07's two money movements applies,
     * and a `false` from either means a concurrent worker got there first —
     * harmless, and already in the audit trail.
     */
    private function applyDefinitive(PayoutItemSnapshot $item, TransferResult $result, CarbonImmutable $now): PayoutItemStatus
    {
        if ($result->status === TransferStatus::SUCCEEDED) {
            ($this->settlePayoutItem)($item, $result->providerReference, $now);

            return PayoutItemStatus::SUCCEEDED;
        }

        ($this->reversePayoutItem)($item, $result->failureCode ?? 'declined', $now);

        return PayoutItemStatus::FAILED;
    }

    /**
     * The provider has had its grace period and still has no record of this
     * key, so the transfer very probably never happened.
     *
     * "Very probably" is why the item returns to `reserved` to be *sent again*
     * rather than being reversed: reversing would put the money back in
     * `available` on the strength of a status API, and if that API were wrong
     * the instructor would be paid twice. Resending with the same key is the
     * conservative choice — the provider's dedup makes it a no-op if the
     * transfer did exist after all.
     */
    private function resend(PayoutItemSnapshot $item): PayoutItemStatus
    {
        if (! $this->payoutItems->markReservedForResend($item->id, 'provider had no record after the grace window')) {
            /**
             * Something else moved it while we were asking — a worker retry
             * that got a better answer. Report what it actually became rather
             * than the status we read a moment ago.
             */
            $current = $this->payoutItems->find($item->id);

            return $current === null ? PayoutItemStatus::NEEDS_REVIEW : $current->status;
        }

        ProcessPayoutItemJob::dispatch($item->id)->afterCommit();

        return PayoutItemStatus::RESERVED;
    }

    /**
     * Still undecided: back off, or hand it over.
     *
     * The escalation is to `needs_review`, never to `failed`. Twenty-four hours
     * of silence tells us nothing about whether the money moved, and the only
     * safe thing an automated system can do with that is stop and say so.
     */
    private function waitOrEscalate(PayoutItemSnapshot $item, TransferResult $result, CarbonImmutable $now): PayoutItemStatus
    {
        if (ReconciliationSchedule::hasExhaustedPatience($item->submittedAt, $now)) {
            ($this->flagPayoutItemForReview)($item->id, "unresolved 24h after submission; provider says {$result->status->value}");

            return PayoutItemStatus::NEEDS_REVIEW;
        }

        $this->payoutItems->markUnknown(
            $item->id,
            ReconciliationSchedule::nextCheckAt($now, $this->payoutItems->statusCheckCount($item->id)),
            "provider says {$result->status->value}",
        );

        return PayoutItemStatus::UNKNOWN;
    }
}
