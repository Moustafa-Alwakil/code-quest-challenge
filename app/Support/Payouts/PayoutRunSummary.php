<?php

declare(strict_types=1);

namespace App\Support\Payouts;

use App\Enums\PayoutRunStatus;

/**
 * What one `payouts:run` invocation did, for the command to print (F06).
 *
 * `reserved` and `dispatchable` are separate counts on purpose. A resume of a
 * crashed run reserves nothing new and still has items to hand out; a second
 * run of a completed key has neither. Folding them together would make those
 * two look alike in the output, and they are the two cases an operator most
 * needs to tell apart.
 */
final readonly class PayoutRunSummary
{
    private function __construct(
        public ?int $runId,
        public string $runKey,
        public PayoutRunStatus $status,
        public int $released,
        public int $considered,
        public int $reserved,
        public int $reservedMinor,
        public int $skipped,
        public int $dispatchable,
        public bool $dryRun,
        public bool $alreadyFinished,
    ) {}

    public static function of(
        ?int $runId,
        string $runKey,
        PayoutRunStatus $status,
        int $released,
        int $considered,
        int $reserved,
        int $reservedMinor,
        int $skipped,
        int $dispatchable,
        bool $dryRun = false,
        bool $alreadyFinished = false,
    ): self {
        return new self(
            $runId,
            $runKey,
            $status,
            $released,
            $considered,
            $reserved,
            $reservedMinor,
            $skipped,
            $dispatchable,
            $dryRun,
            $alreadyFinished,
        );
    }
}
