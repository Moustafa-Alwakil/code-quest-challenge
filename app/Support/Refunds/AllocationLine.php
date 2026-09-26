<?php

declare(strict_types=1);

namespace App\Support\Refunds;

/**
 * One earning allocation, as a clawback needs to see it (F09).
 *
 * `isReleased` is the only state that matters here, and it decides which
 * snapshot field the money comes out of: held earnings cost the instructor
 * nothing to reverse, released ones come out of `available` and may leave them
 * owing the platform (D-6, D-7).
 */
final readonly class AllocationLine
{
    public function __construct(
        public int $id,
        public int $instructorId,
        public int $amountMinor,
        public bool $isReleased,
    ) {}
}
