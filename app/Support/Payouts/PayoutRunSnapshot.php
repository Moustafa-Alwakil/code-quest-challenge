<?php

declare(strict_types=1);

namespace App\Support\Payouts;

use App\Enums\PayoutRunStatus;

/**
 * What an Action needs to know about a payout run (F06).
 *
 * A value object rather than the model, because an Action may not touch
 * Eloquent: handing one back would put a live query surface — relations, lazy
 * loads, `save()` — into the use-case layer, which is exactly the boundary the
 * arch tests exist to hold. The Service reads the row; this is what crosses.
 */
final readonly class PayoutRunSnapshot
{
    public function __construct(
        public int $id,
        public string $runKey,
        public PayoutRunStatus $status,
        public int $itemCount,
        public int $totalMinor,
    ) {}
}
