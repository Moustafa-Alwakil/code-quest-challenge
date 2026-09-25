<?php

declare(strict_types=1);

namespace App\Support\Ledger;

/**
 * What `ledger:verify` found (F03).
 *
 * Carries the counts as well as the failures, so a green run still reports how
 * much it actually looked at — "0 mismatches" over 0 rows proves nothing.
 */
final readonly class LedgerVerificationResult
{
    /**
     * @param list<LedgerMismatch> $mismatches
     */
    private function __construct(
        public array $mismatches,
        public int $entriesChecked,
        public int $transactionsChecked,
        public int $instructorsChecked,
        public bool $stoppedEarly,
    ) {}

    /**
     * @param list<LedgerMismatch> $mismatches
     */
    public static function of(
        array $mismatches,
        int $entriesChecked,
        int $transactionsChecked,
        int $instructorsChecked,
        bool $stoppedEarly = false,
    ): self {
        return new self($mismatches, $entriesChecked, $transactionsChecked, $instructorsChecked, $stoppedEarly);
    }

    public function isClean(): bool
    {
        return $this->mismatches === [];
    }

    public function mismatchCount(): int
    {
        return count($this->mismatches);
    }

    /**
     * @return list<array{check: string, scope: string, field: string, expected: string, actual: string, delta: string}>
     */
    public function toRows(): array
    {
        return array_map(static fn (LedgerMismatch $mismatch): array => $mismatch->toRow(), $this->mismatches);
    }
}
