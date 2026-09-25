<?php

declare(strict_types=1);

namespace App\DTOs\Ledger;

use InvalidArgumentException;

/**
 * What a verification run was asked to look at (F03).
 *
 * Parsing the console's strings happens here, once, so nothing inward ever sees
 * an option value. No Illuminate imports: a DTO is a contract, not a place to
 * reach for the framework.
 */
final readonly class VerifyLedgerData
{
    /**
     * Rows per keyset page. Large enough to keep round trips down, small enough
     * that a run over millions of entries stays flat in memory.
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    private function __construct(
        public ?int $instructorId,
        public bool $failFast,
        public int $chunkSize,
    ) {}

    /**
     * @param string|null $instructor the raw `--instructor=` option, if given
     *
     * @throws InvalidArgumentException when the option is not a positive integer
     */
    public static function fromCommand(?string $instructor, bool $failFast, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        $instructorId = null;

        if ($instructor !== null && $instructor !== '') {
            if (ctype_digit($instructor) === false || (int) $instructor <= 0) {
                throw new InvalidArgumentException("--instructor must be a positive integer, got '{$instructor}'.");
            }

            $instructorId = (int) $instructor;
        }

        if ($chunkSize < 1) {
            throw new InvalidArgumentException("Chunk size must be at least 1, got {$chunkSize}.");
        }

        return new self($instructorId, $failFast, $chunkSize);
    }

    /**
     * The whole ledger, default paging — what the scheduler and CI run.
     */
    public static function everything(): self
    {
        return new self(null, false, self::DEFAULT_CHUNK_SIZE);
    }
}
