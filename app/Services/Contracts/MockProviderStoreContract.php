<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\TransferStatus;
use App\Models\MockProviderTransfer;
use App\Support\Payouts\TransferResult;

/**
 * The mock provider's own database (R8).
 *
 * Not part of this application's domain — it is the *other side* of the
 * integration, persisted so a retry from a different worker process meets the
 * same dedup a real provider would give it.
 */
interface MockProviderStoreContract
{
    /**
     * The transfer already recorded for this key, if the provider has seen it.
     *
     * The dedup itself: a caller that finds a row here must return its result
     * rather than moving money again, whatever it was about to do.
     */
    public function find(string $idempotencyKey): ?MockProviderTransfer;

    /**
     * Counts the call without performing anything — every `transfer()` lands
     * here, including the ones the dedup then answers from memory.
     */
    public function recordCall(string $idempotencyKey): void;

    /**
     * Performs a transfer for a key never seen before.
     *
     * `transfer_executions` is set to 1 here and nowhere else, which is what
     * makes it the suite's source of truth for "the money moved once": a
     * provider whose dedup had broken would have to write a second row, and
     * UNIQUE `idempotency_key` refuses that.
     */
    public function record(string $idempotencyKey, string $accountRef, int $amountMinor, string $currency, TransferStatus $status, int $confirmAfterChecks = 0, ?string $failureCode = null): MockProviderTransfer;

    /**
     * Answers a status query, ageing a `pending` transfer towards success.
     *
     * Video scenario 5: a provider that accepts a transfer and confirms it
     * several checks later. The counter advances on every question asked, so
     * the flip happens on the provider's schedule rather than on ours.
     */
    public function checkStatus(MockProviderTransfer $transfer): TransferResult;

    /**
     * How many times the provider actually moved money for this key. One, for
     * any key it has ever transferred; zero for one it has never seen.
     */
    public function transferCount(string $idempotencyKey): int;

    /**
     * How many times `transfer()` was *called* for this key. Greater than
     * `transferCount()` is the healthy case: it means a retry happened and the
     * dedup absorbed it.
     */
    public function callCount(string $idempotencyKey): int;

    /**
     * The provider's stored verdict on a transfer, as a result the application
     * can act on.
     *
     * An instance method rather than a static helper so it fits the contract at
     * all: a static cannot be called through an interface, which would have
     * quietly forced both mock providers back to the concrete store.
     */
    public function resultFor(MockProviderTransfer $transfer): TransferResult;
}
