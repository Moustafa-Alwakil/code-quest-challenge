<?php

declare(strict_types=1);

namespace App\Contracts;

use Carbon\CarbonImmutable;

/**
 * The `subscription_period_engagement` rollup — the weights recognition divides
 * an instructor pool by (D-2).
 *
 * Read-only from this application's point of view: the rows are seeded here and
 * would be produced by a nightly job folding raw view events in production.
 * A contract with one method, because that is genuinely all recognition needs.
 */
interface EngagementServiceContract
{
    /**
     * The engagement weights for one subscription-period, keyed by instructor.
     *
     * Zero-unit rows are filtered out in SQL rather than in PHP: they are not a
     * weight of nothing, they are an instructor who did not participate, and
     * `Allocator::largestRemainder()` would otherwise be handed a key it must
     * return a zero for and the caller must then discard.
     *
     * Ordered by instructor id ascending, which is the order the balance
     * snapshot must be touched in to avoid deadlocking a concurrent posting.
     *
     * @return array<int, int> instructor id => units, ascending by instructor id
     */
    public function unitsFor(int $subscriptionId, CarbonImmutable $periodStart): array;
}
