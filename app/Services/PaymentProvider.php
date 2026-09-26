<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProviderTimeoutException;
use App\Exceptions\ProviderUnavailableException;
use App\Support\Payouts\TransferResult;

/**
 * The external payout provider, as this system needs it (F07, D-8).
 *
 * Two operations, and the second is not optional: without `getStatus()` a
 * timeout is unrecoverable, because the only safe reaction to "we do not know"
 * is to ask again. Any real provider worth integrating offers both.
 *
 * **The contract that makes retries safe** is that `transfer()` is keyed by
 * `idempotencyKey` and the provider dedups on it server-side: calling it twice
 * with one key moves money once and returns the original result. That is what
 * turns this system's at-least-once job delivery into effectively-once
 * payment, and it is the assumption every mock here models faithfully.
 */
interface PaymentProvider
{
    /**
     * Send one payout, keyed so that sending it again is free.
     *
     * Must never be called inside a database transaction (PLAN §8.2): a
     * transaction held across the network either rolls a real transfer out of
     * the records, or pins a row lock for the provider's entire timeout.
     *
     * @throws ProviderTimeoutException     the request may have been processed — outcome unknown
     * @throws ProviderUnavailableException the request provably never left, so resending is safe
     */
    public function transfer(
        string $idempotencyKey,
        string $accountRef,
        int $amountMinor,
        string $currency,
    ): TransferResult;

    /**
     * What the provider currently says about a key it may never have seen.
     *
     * `NOT_FOUND` is a real answer here, and the useful one: it means the
     * transfer provably did not happen and may be sent with the same key.
     */
    public function getStatus(string $idempotencyKey): TransferResult;
}
