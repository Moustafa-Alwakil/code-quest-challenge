<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Support\Ledger\LedgerVerificationResult;

/**
 * The check that the snapshot and the ledger agree — invariants I1 to I8.
 *
 * A cache nobody verifies is a second source of truth waiting to drift. This is
 * the verification, and it is deliberately a contract of one method: callers
 * ask whether the money is right, not how it was established.
 */
interface LedgerVerificationServiceContract
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Runs the four checks and returns everything that disagreed.
     *
     * Restricting to one instructor skips checks 1 and 2 by design: a single
     * instructor's legs are one side of transactions whose other side sits on
     * platform accounts, so their sum is *supposed* to be non-zero. Only the
     * whole ledger balances.
     */
    public function verify(?int $instructorId = null, bool $failFast = false, int $chunkSize = self::DEFAULT_CHUNK_SIZE): LedgerVerificationResult;
}
