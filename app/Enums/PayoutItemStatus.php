<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one instructor's payment stands (F06-F08, PLAN §8.1).
 *
 * Four of these are internal states for a provider that reports three outcomes
 * (D-8). `UNKNOWN` is the one that earns its keep: a timeout is neither a
 * success nor a failure, and calling it either is how money gets sent twice or
 * quietly lost. It is a state, not an error, and reconciliation resolves it.
 *
 * Every transition is a conditional `UPDATE ... WHERE status = ?` with
 * `affected === 1` asserted — a compare-and-swap the database enforces, which
 * makes each transition idempotent on its own.
 */
enum PayoutItemStatus: string
{
    case RESERVED = 'reserved';

    case SUBMITTED = 'submitted';

    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';

    case UNKNOWN = 'unknown';

    case NEEDS_REVIEW = 'needs_review';

    /**
     * Whether the money has stopped moving. `NEEDS_REVIEW` is deliberately not
     * terminal in this sense: it is where work goes to be looked at by a human,
     * and a run holding one has not finished cleanly.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::SUCCEEDED, self::FAILED => true,
            self::RESERVED, self::SUBMITTED, self::UNKNOWN, self::NEEDS_REVIEW => false,
        };
    }

    /**
     * Whether a payout run may still hand this item to a worker.
     */
    public function isDispatchable(): bool
    {
        return $this === self::RESERVED;
    }
}
