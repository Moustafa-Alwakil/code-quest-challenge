<?php

declare(strict_types=1);

namespace App\DTOs\Payouts;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One reconciliation sweep's window (F08).
 *
 * Reads the clock once, in the named constructor, and carries the instant
 * (R27): both sweeps have to select against the same "now", or an item could
 * fall between them — due for the uncertain sweep at one instant and not yet
 * stranded at another.
 */
final readonly class ReconcilePayoutsData
{
    /**
     * Items per sweep. A bound rather than a target: whatever this run does not
     * reach is still due five minutes later, because the predicate is a
     * property of the row and not of when we looked.
     */
    private const DEFAULT_LIMIT = 500;

    private function __construct(
        public CarbonImmutable $asOf,
        public int $limit,
        public bool $sync,
    ) {}

    /**
     * @param string|null $limit the raw `--limit=` option
     *
     * @throws InvalidArgumentException on a limit below 1
     */
    public static function fromCommand(?string $limit = null, bool $sync = false): self
    {
        $resolved = self::DEFAULT_LIMIT;

        if ($limit !== null && mb_trim($limit) !== '') {
            if (ctype_digit($limit) === false || (int) $limit < 1) {
                throw new InvalidArgumentException("--limit must be a positive integer, got '{$limit}'.");
            }

            $resolved = (int) $limit;
        }

        return new self(CarbonImmutable::now(), $resolved, $sync);
    }
}
