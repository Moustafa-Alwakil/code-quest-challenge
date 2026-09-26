<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Engagement;
use Carbon\CarbonImmutable;

/**
 * The `subscription_period_engagement` rollup — the weights recognition divides
 * an instructor pool by (D-2, F02).
 *
 * Read-only from this application's point of view: the rows are produced by a
 * seeder here, and in production by a nightly job folding raw view events. F05
 * reads them at recognition time and never again, which is the late-data policy
 * F02 documents — engagement recorded for a period that has already been
 * recognized changes nothing.
 */
final class EngagementService
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
    public function unitsFor(int $subscriptionId, CarbonImmutable $periodStart): array
    {
        /** @var array<int, int> $units */
        $units = Engagement::query()
            ->where('subscription_id', $subscriptionId)
            ->where('period_start', $periodStart->toDateString())
            ->where('units', '>', 0)
            ->orderBy('instructor_id')
            ->pluck('units', 'instructor_id')
            ->all();

        return $units;
    }
}
