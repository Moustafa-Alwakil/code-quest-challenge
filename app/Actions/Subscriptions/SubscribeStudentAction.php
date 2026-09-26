<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\DTOs\Subscriptions\SubscribeStudentData;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\PaymentMismatchException;
use App\Services\AccrualService;
use App\Services\LedgerService;
use App\Services\PlanService;
use App\Services\SubscriptionService;
use App\Support\Accrual\AccrualSchedule;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use App\Support\Money;
use App\Support\Subscriptions\SubscriptionOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Records a captured payment as a subscription, a liability and a schedule — the
 * single entry point into the money core (F04, D-1).
 *
 * One transaction, and no provider call inside it: this use case records a fact
 * that has already happened, so there is nothing to call and nothing to leave
 * half-done. Every write is either committed together or absent.
 *
 * Idempotency is the UNIQUE index on `payments.external_ref`, twice over:
 *
 * - a *sequential* replay is caught by step 1 and writes nothing at all;
 * - a *concurrent* duplicate gets past step 1 — it cannot see the other
 *   transaction's uncommitted row — and is stopped by the index at step 5. That
 *   transaction rolls back whole, and its retry takes step 1's path.
 *
 * Neither depends on a lock. With the cache server switched off, one payment
 * still produces one subscription, one posting and one schedule.
 */
final class SubscribeStudentAction
{
    public function __construct(
        private PlanService $plans,
        private SubscriptionService $subscriptions,
        private AccrualService $accrual,
        private LedgerService $ledger,
    ) {}

    /**
     * @throws PaymentMismatchException when the captured amount or currency is not the plan's price
     */
    public function __invoke(SubscribeStudentData $data): SubscriptionOutcome
    {
        return DB::transaction(function () use ($data): SubscriptionOutcome {
            /** Step 1 — already on file? Then this call is a replay and writes nothing. */
            $recorded = $this->subscriptions->subscriptionIdForExternalRef($data->externalRef);

            if ($recorded !== null) {
                return SubscriptionOutcome::replayOf($recorded);
            }

            /** Step 2 — the payment has to be exactly what the plan costs, or nothing is recorded. */
            $terms = $this->plans->termsFor($data->planId);
            $amount = Money::of($data->amountMinor, $data->currency);

            if ($amount->currency !== $terms->price->currency) {
                throw PaymentMismatchException::currency($data->externalRef, $terms->price->currency, $amount->currency);
            }

            if ($amount->minor !== $terms->price->minor) {
                throw PaymentMismatchException::amount($data->externalRef, $terms->price->minor, $amount->minor, $amount->currency);
            }

            /**
             * Step 3 — the schedule is computed before anything is written, so a
             * term that cannot be split exactly never becomes a subscription.
             * It is also where `term_end` and `term_days` come from: one piece
             * of anchor arithmetic, used by both the row and the periods (R10).
             */
            $schedule = AccrualSchedule::forTerm($data->capturedAt, $terms->intervalMonths, $terms->price);

            /** Step 4 — the term, with the price snapshotted at purchase. */
            $subscriptionId = $this->subscriptions->createActive(
                $data->userId,
                $terms->planId,
                $schedule->termStart,
                $schedule->termEnd,
                $schedule->termDays,
                $terms->price,
            );

            /** Step 5 — the payment. A concurrent duplicate dies here, on the unique index. */
            $paymentId = $this->subscriptions->recordPayment(
                $subscriptionId,
                $data->externalRef,
                $amount,
                $data->capturedAt,
            );

            /**
             * Step 6 — cash in, an equal liability to deliver the term.
             *
             * No BalanceDelta: no instructor has earned anything yet, and this
             * is the posting that proves a transaction can legitimately move no
             * instructor's snapshot at all (R18). The liability is keyed per
             * subscription (R5), so "this term's deferred revenue returned to
             * zero" is a question the ledger can answer on its own.
             */
            $this->ledger->post(LedgerTransaction::of(
                LedgerEntryType::PAYMENT_RECEIVED,
                'payment',
                $paymentId,
                LedgerLeg::debit(LedgerAccountType::PLATFORM_CASH, 0, $amount),
                LedgerLeg::credit(LedgerAccountType::DEFERRED_REVENUE, $subscriptionId, $amount),
            ));

            /**
             * Step 7 — the schedule. The return value is ignored on purpose: it
             * can only be a full write here, because a fresh payment id means a
             * fresh subscription id, and a replay already returned at step 1.
             */
            $this->accrual->scheduleFor($subscriptionId, $schedule);

            return SubscriptionOutcome::recorded($subscriptionId);
        });
    }
}
