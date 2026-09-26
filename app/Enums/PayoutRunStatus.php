<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far one payout run has got (F06, PLAN §8.1).
 *
 * `completed_with_pending` is the honest status D-8 demands: a run whose items
 * are not all terminal has not failed, and has not finished either. Collapsing
 * it into `completed` would claim an outcome the provider has not given yet,
 * and F08 re-evaluates the run as its `unknown` items resolve.
 */
enum PayoutRunStatus: string
{
    case OPEN = 'open';

    case DISPATCHED = 'dispatched';

    case COMPLETED = 'completed';

    case COMPLETED_WITH_PENDING = 'completed_with_pending';

    /**
     * Whether the run has nothing left to do. A completed run is skipped by a
     * re-invocation of its key rather than resumed.
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::COMPLETED, self::COMPLETED_WITH_PENDING => true,
            self::OPEN, self::DISPATCHED => false,
        };
    }
}
