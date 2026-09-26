<?php

declare(strict_types=1);

namespace App\Support\Accrual;

/**
 * What one `ledger:accrue` run did, for the command to print (F05).
 *
 * `dispatched` and `recognized` are deliberately separate counts. A queued run
 * knows only how many periods it handed to workers; a `--sync` run knows how
 * many it actually recognized, and how many were already someone else's. Folding
 * the two into one number would make a queued run look like it had recognized
 * work it has not started.
 */
final readonly class AccrualRunSummary
{
    private function __construct(
        public int $periodsFound,
        public int $periodsDispatched,
        public int $periodsRecognized,
        public int $periodsSkipped,
        public int $allocationsWritten,
        public int $earningsReleased,
    ) {}

    public static function of(
        int $periodsFound,
        int $periodsDispatched,
        int $periodsRecognized,
        int $periodsSkipped,
        int $allocationsWritten,
        int $earningsReleased,
    ): self {
        return new self(
            $periodsFound,
            $periodsDispatched,
            $periodsRecognized,
            $periodsSkipped,
            $allocationsWritten,
            $earningsReleased,
        );
    }
}
