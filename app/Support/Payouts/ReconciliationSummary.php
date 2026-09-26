<?php

declare(strict_types=1);

namespace App\Support\Payouts;

/**
 * What one reconciliation sweep found (F08).
 *
 * The two counts stay separate because they describe different failures:
 * `uncertain` is the provider not having answered, `stranded` is our own queue
 * having dropped a job. A run that keeps reporting stranded items has a worker
 * problem, not a provider problem, and one number could not say that.
 */
final readonly class ReconciliationSummary
{
    private function __construct(
        public int $uncertain,
        public int $stranded,
        public int $resolved,
        public bool $sync,
    ) {}

    public static function of(int $uncertain, int $stranded, int $resolved, bool $sync): self
    {
        return new self($uncertain, $stranded, $resolved, $sync);
    }
}
