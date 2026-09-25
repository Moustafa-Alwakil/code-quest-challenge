<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\DTOs\Ledger\VerifyLedgerData;
use App\Services\LedgerVerificationService;
use App\Support\Ledger\LedgerVerificationResult;

/**
 * Proves the snapshot agrees with the ledger (F03).
 *
 * Read-only, so no transaction: opening one would only give the run a stale
 * snapshot of a table that is being written to while it reads. A mismatch is
 * returned as data, not thrown — the caller decides whether it is an exit code,
 * an alert or a screen.
 */
final class VerifyLedgerAction
{
    public function __construct(
        private LedgerVerificationService $verification,
    ) {}

    public function __invoke(VerifyLedgerData $data): LedgerVerificationResult
    {
        return $this->verification->verify(
            $data->instructorId,
            $data->failFast,
            $data->chunkSize,
        );
    }
}
