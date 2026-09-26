<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransferStatus;
use App\Exceptions\ProviderTimeoutException;
use App\Exceptions\ProviderUnavailableException;
use App\Support\Payouts\TransferResult;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * A provider whose next answer the test chooses (F07).
 *
 * Deterministic where `RandomMockProvider` rolls dice, so a test can say "this
 * transfer times out after succeeding, and the retry then finds it" and mean
 * exactly that. Outcomes are queued and consumed in order.
 *
 * Two things it does beyond scripting:
 *
 * - it refuses to be called inside a database transaction, which turns PLAN
 *   §8.2's ordering rule from a convention into a failing test;
 * - it dedups exactly like the random mock, because a scripted provider that
 *   forgot to would let every retry test pass for the wrong reason.
 */
final class ScriptedMockProvider implements PaymentProvider
{
    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_PERMANENT_FAILURE = 'permanent_failure';

    public const OUTCOME_TIMEOUT_AFTER_SUCCESS = 'timeout_after_success';

    public const OUTCOME_TIMEOUT_BEFORE_SEND = 'timeout_before_send';

    public const OUTCOME_DELAYED_CONFIRMATION = 'delayed_confirmation';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    /** @var list<string> */
    private array $script = [];

    /**
     * The transaction depth this provider was born at.
     *
     * Zero in production. One under `RefreshDatabase`, which holds a
     * transaction open for the whole test — so a flat `level === 0` check would
     * fail every feature test and prove nothing. What is actually forbidden is
     * the application *opening* a transaction around the call, and that shows
     * up as a level above this baseline in either environment.
     */
    private readonly int $baselineTransactionLevel;

    public function __construct(
        private MockProviderStore $store,
    ) {
        $this->baselineTransactionLevel = DB::transactionLevel();
    }

    /**
     * Queues the outcomes `transfer()` will produce, in order.
     *
     * Only calls that reach the provider consume one: a call the dedup answers
     * from its own records never gets that far, which is what the retry tests
     * are asserting.
     *
     * @param list<string> $outcomes
     */
    public function script(array $outcomes): self
    {
        foreach ($outcomes as $outcome) {
            $this->script[] = $outcome;
        }

        return $this;
    }

    public function transfer(
        string $idempotencyKey,
        string $accountRef,
        int $amountMinor,
        string $currency,
    ): TransferResult {
        $this->assertOutsideTransaction($idempotencyKey);

        /**
         * Provider-side dedup, before anything else. A key this provider has
         * already acted on gets its original answer back and moves no money —
         * the guarantee every retry in this system leans on.
         */
        $existing = $this->store->find($idempotencyKey);

        if ($existing !== null) {
            $this->store->recordCall($idempotencyKey);

            return MockProviderStore::resultFor($existing->refresh());
        }

        $outcome = array_shift($this->script) ?? self::OUTCOME_SUCCESS;

        /** Nothing is recorded: the request provably never arrived. */
        if ($outcome === self::OUTCOME_UNAVAILABLE) {
            throw ProviderUnavailableException::forKey($idempotencyKey, 'scripted connection failure');
        }

        /** Likewise — this one times out *before* the provider saw it. */
        if ($outcome === self::OUTCOME_TIMEOUT_BEFORE_SEND) {
            throw ProviderTimeoutException::forKey($idempotencyKey);
        }

        $transfer = match ($outcome) {
            self::OUTCOME_SUCCESS, self::OUTCOME_TIMEOUT_AFTER_SUCCESS => $this->store->record(
                $idempotencyKey, $accountRef, $amountMinor, $currency, TransferStatus::SUCCEEDED,
            ),
            self::OUTCOME_PERMANENT_FAILURE => $this->store->record(
                $idempotencyKey, $accountRef, $amountMinor, $currency, TransferStatus::FAILED,
                failureCode: 'account_closed',
            ),
            self::OUTCOME_DELAYED_CONFIRMATION => $this->store->record(
                $idempotencyKey, $accountRef, $amountMinor, $currency, TransferStatus::PENDING,
                confirmAfterChecks: 2,
            ),
            default => throw new LogicException("ScriptedMockProvider does not know the outcome '{$outcome}'."),
        };

        /**
         * The money moved and then the answer was lost. This is the case the
         * whole design exists for: the caller must record `unknown`, not
         * `failed`, or it will return a balance the provider has already paid.
         */
        if ($outcome === self::OUTCOME_TIMEOUT_AFTER_SUCCESS) {
            throw ProviderTimeoutException::forKey($idempotencyKey);
        }

        return MockProviderStore::resultFor($transfer);
    }

    public function getStatus(string $idempotencyKey): TransferResult
    {
        $transfer = $this->store->find($idempotencyKey);

        if ($transfer === null) {
            return TransferResult::notFound();
        }

        return $this->store->checkStatus($transfer);
    }

    /**
     * The source of truth for "the money moved once".
     */
    public function transferCount(string $idempotencyKey): int
    {
        return $this->store->transferCount($idempotencyKey);
    }

    /**
     * How many times we asked. Exceeding `transferCount()` is the point of a
     * retry test, not a failure of one.
     */
    public function callCount(string $idempotencyKey): int
    {
        return $this->store->callCount($idempotencyKey);
    }

    /**
     * PLAN §8.2, as an assertion rather than a comment.
     *
     * A transaction open here would mean the application is holding row locks
     * across a network call it cannot bound — and, worse, that a rollback could
     * erase the record of a transfer that really happened.
     */
    private function assertOutsideTransaction(string $idempotencyKey): void
    {
        $level = DB::transactionLevel();

        if ($level > $this->baselineTransactionLevel) {
            throw new RuntimeException(
                "transfer({$idempotencyKey}) was called inside a database transaction "
                ."(level {$level}, expected at most {$this->baselineTransactionLevel}). "
                .'Commit the intent, call the provider, then commit the outcome (PLAN §8.2).'
            );
        }
    }
}
