<?php

declare(strict_types=1);

namespace App\Actions\Payouts;

use App\Enums\PayoutAttemptOperation;
use App\Enums\PayoutItemStatus;
use App\Enums\TransferStatus;
use App\Exceptions\ProviderTimeoutException;
use App\Exceptions\ProviderUnavailableException;
use App\Services\PaymentProvider;
use App\Services\PayoutItemService;
use App\Support\Payouts\PayoutItemSnapshot;
use App\Support\Payouts\TransferResult;
use Carbon\CarbonImmutable;

/**
 * Sends one reserved payout, effectively once (F07, D-8, D-10).
 *
 * **The ordering rule, which is the whole action** (PLAN §8.2):
 *
 * 1. commit the intent — `reserved → submitted`, its own transaction;
 * 2. call the provider — outside every transaction;
 * 3. commit the outcome — its own transaction, CAS from what we expect.
 *
 * A transaction held across step 2 would either roll a real transfer out of the
 * records or pin a row lock for the provider's entire timeout. Neither is
 * survivable at this scale, and `ScriptedMockProvider` fails the suite if this
 * action ever tries.
 *
 * **Status-first on retry.** A second attempt asks `getStatus()` before it
 * sends anything. Our mock provider dedups perfectly, so sending again would be
 * harmless against it — but a real provider's dedup may be weaker or its window
 * shorter, and asking costs one call. `NOT_FOUND` is the answer that makes
 * resending provably safe.
 *
 * Nothing here returns money to `available` on a guess. Only a provider saying
 * `failed` does that; everything else either settles, waits, or becomes
 * `unknown` with the money still reserved.
 */
final class ProcessPayoutItemAction
{
    public function __construct(
        private PayoutItemService $payoutItems,
        private PaymentProvider $provider,
        private SettlePayoutItemAction $settlePayoutItem,
        private ReversePayoutItemAction $reversePayoutItem,
    ) {}

    /**
     * @return PayoutItemStatus the status the item holds when this call returns
     *
     * @throws ProviderUnavailableException rethrown so the queue retries; nothing was sent
     */
    public function __invoke(int $payoutItemId): PayoutItemStatus
    {
        $item = $this->payoutItems->find($payoutItemId);

        if ($item === null) {
            return PayoutItemStatus::NEEDS_REVIEW;
        }

        /** Step 1 — terminal, or already parked for a human. Nothing to do. */
        if ($item->status->isTerminal() || $item->status === PayoutItemStatus::NEEDS_REVIEW) {
            return $item->status;
        }

        /**
         * Step 2 — a retry asks before it acts. A definitive answer settles the
         * item without a second transfer ever being attempted; `pending` leaves
         * it to reconciliation; `not_found` means the provider never saw it, so
         * sending with the same key cannot duplicate anything.
         */
        if ($item->status !== PayoutItemStatus::RESERVED) {
            $status = $this->askProvider($item);

            if ($status !== null) {
                return $status;
            }
        }

        /** Step 3 — claim it, and commit that before the network call. */
        if ($item->status === PayoutItemStatus::RESERVED && ! $this->payoutItems->markSubmitted($item->id, CarbonImmutable::now())) {
            /**
             * Another worker claimed it between our read and our write. The CAS
             * is what makes that safe: the loser sends nothing and reports
             * whatever the winner has since made of it.
             */
            $claimed = $this->payoutItems->find($payoutItemId);

            return $claimed === null ? PayoutItemStatus::NEEDS_REVIEW : $claimed->status;
        }

        return $this->send($item);
    }

    private static function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Asks the provider what it already knows, and acts only on a definitive
     * answer.
     *
     * @return PayoutItemStatus|null the settled status, or null to continue and send
     */
    private function askProvider(PayoutItemSnapshot $item): ?PayoutItemStatus
    {
        $startedAt = hrtime(true);
        $result = $this->provider->getStatus($item->idempotencyKey);

        $this->payoutItems->recordAttempt(
            $item->id,
            PayoutAttemptOperation::STATUS,
            "getStatus {$item->idempotencyKey}",
            $result->describe(),
            $result->status->value,
            self::elapsedMs($startedAt),
        );

        if ($result->status->isDefinitive()) {
            return $this->applyDefinitive($item, $result);
        }

        /**
         * Accepted but undecided. Reconciliation owns it from here (F08) — this
         * worker must not send again, because the provider is holding a
         * transfer for this key that may yet succeed.
         */
        if ($result->status === TransferStatus::PENDING) {
            return $this->park($item, 'provider still processing');
        }

        /** `NOT_FOUND`: never seen, so sending with the same key is safe. */
        return null;
    }

    /**
     * The network call, and only it, happens outside every transaction.
     */
    private function send(PayoutItemSnapshot $item): PayoutItemStatus
    {
        $startedAt = hrtime(true);
        $request = "transfer {$item->amountMinor} {$item->currency} to {$item->accountRef}";

        try {
            $result = $this->provider->transfer(
                $item->idempotencyKey,
                $item->accountRef,
                $item->amountMinor,
                $item->currency,
            );
        } catch (ProviderTimeoutException $timeout) {
            $this->payoutItems->recordAttempt(
                $item->id,
                PayoutAttemptOperation::TRANSFER,
                $request,
                $timeout->getMessage(),
                'timeout',
                self::elapsedMs($startedAt),
            );

            /**
             * D-8, in one line: we do not know, so we do not guess. The money
             * stays reserved and F08 asks again.
             */
            return $this->park($item, 'provider timeout');
        } catch (ProviderUnavailableException $unavailable) {
            $this->payoutItems->recordAttempt(
                $item->id,
                PayoutAttemptOperation::TRANSFER,
                $request,
                $unavailable->getMessage(),
                'unavailable',
                self::elapsedMs($startedAt),
            );

            /**
             * Provably not sent, so the item stays `submitted` and the queue
             * retries with the same key. Rethrown rather than swallowed: the
             * backoff ladder is the right place to wait for a provider that is
             * down, not this worker.
             */
            throw $unavailable;
        }

        $this->payoutItems->recordAttempt(
            $item->id,
            PayoutAttemptOperation::TRANSFER,
            $request,
            $result->describe(),
            $result->status->value,
            self::elapsedMs($startedAt),
        );

        if ($result->status->isDefinitive()) {
            return $this->applyDefinitive($item, $result);
        }

        /**
         * `PENDING` is the provider accepting the transfer without committing
         * to an outcome. `NOT_FOUND` from a *transfer* call should not happen
         * at all — a provider that says it has no record of what we just sent
         * it is one we cannot reason about — and both get the same treatment
         * for the same reason: the money is out and only asking again can
         * establish where it went.
         */
        return $this->park($item, "provider returned {$result->status->value} to a transfer");
    }

    /**
     * Step 4 — the outcome, each in its own transaction.
     *
     * A `false` from either action means the item was already terminal when we
     * got here: a duplicate delivery, or a response that arrived late. Both are
     * harmless, both are already in the audit trail, and neither changes money —
     * so the status reported is the one the provider gave either way.
     */
    private function applyDefinitive(PayoutItemSnapshot $item, TransferResult $result): PayoutItemStatus
    {
        $now = CarbonImmutable::now();

        if ($result->status === TransferStatus::SUCCEEDED) {
            ($this->settlePayoutItem)($item, $result->providerReference, $now);

            return PayoutItemStatus::SUCCEEDED;
        }

        ($this->reversePayoutItem)($item, $result->failureCode ?? 'declined', $now);

        return PayoutItemStatus::FAILED;
    }

    private function park(PayoutItemSnapshot $item, string $reason): PayoutItemStatus
    {
        $this->payoutItems->markUnknown($item->id, CarbonImmutable::now(), $reason);

        return PayoutItemStatus::UNKNOWN;
    }
}
