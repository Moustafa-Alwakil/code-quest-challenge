<?php

declare(strict_types=1);

namespace App\Support\Ledger;

/**
 * One disagreement found by `ledger:verify` (F03).
 *
 * Deliberately concrete: which check failed, what it was looking at, the value
 * expected and the value found. "The snapshot is a cache — this is how I know
 * it is telling the truth" only lands if the failure names the exact piastre.
 */
final readonly class LedgerMismatch
{
    public const CHECK_LEDGER_SUM = 'ledger sum';

    public const CHECK_TRANSACTION_SUM = 'transaction sum';

    public const CHECK_SNAPSHOT = 'snapshot field';

    public const CHECK_IDENTITY = 'outstanding identity';

    public const CHECK_MISSING_SNAPSHOT = 'missing snapshot';

    public const CHECK_CURRENCY = 'snapshot currency';

    /**
     * Values are carried as strings because not every snapshot field is a
     * number — `currency` is one too (R21). `deltaMinor` is null exactly when
     * subtracting the two would be meaningless.
     */
    private function __construct(
        public string $check,
        public string $scope,
        public string $field,
        public string $expected,
        public string $actual,
        public ?int $deltaMinor,
    ) {}

    /**
     * Check 1 — every piastre that entered the ledger also left it.
     */
    public static function ledgerSum(int $actualMinor): self
    {
        return self::ofMinor(self::CHECK_LEDGER_SUM, 'ledger', 'sum(amount_minor)', 0, $actualMinor);
    }

    /**
     * Check 2 — a single posting is balanced, not merely the table as a whole.
     */
    public static function transactionSum(string $transactionUuid, int $actualMinor): self
    {
        return self::ofMinor(self::CHECK_TRANSACTION_SUM, "transaction {$transactionUuid}", 'sum(amount_minor)', 0, $actualMinor);
    }

    /**
     * Check 3 — the snapshot field equals what the ledger recomputes to.
     */
    public static function snapshotField(int $instructorId, string $field, int $expectedMinor, int $actualMinor): self
    {
        return self::ofMinor(self::CHECK_SNAPSHOT, "instructor {$instructorId}", $field, $expectedMinor, $actualMinor);
    }

    /**
     * Check 4 — `outstanding = available + held + reserved`, from the snapshot's
     * own numbers. This is the line that answers "owed / paid / outstanding".
     */
    public static function outstandingIdentity(int $instructorId, int $outstandingMinor, int $partsMinor): self
    {
        return self::ofMinor(
            self::CHECK_IDENTITY,
            "instructor {$instructorId}",
            'outstanding = available + held + reserved',
            $outstandingMinor,
            $partsMinor,
        );
    }

    /**
     * The ledger owes an instructor money that no snapshot row records.
     */
    public static function missingSnapshot(int $instructorId, int $recomputedOutstandingMinor): self
    {
        return self::ofMinor(
            self::CHECK_MISSING_SNAPSHOT,
            "instructor {$instructorId}",
            'instructor_balances row',
            $recomputedOutstandingMinor,
            0,
        );
    }

    /**
     * Check 3, currency (R21) — `currency` is a snapshot field, and F03's own
     * words are "every snapshot field = its recomputed value".
     */
    public static function currency(int $instructorId, string $expected, string $actual): self
    {
        return new self(self::CHECK_CURRENCY, "instructor {$instructorId}", 'currency', $expected, $actual, null);
    }

    /**
     * @return array{check: string, scope: string, field: string, expected: string, actual: string, delta: string}
     */
    public function toRow(): array
    {
        return [
            'check' => $this->check,
            'scope' => $this->scope,
            'field' => $this->field,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'delta' => match (true) {
                $this->deltaMinor === null => '-',
                $this->deltaMinor > 0 => '+'.$this->deltaMinor,
                default => (string) $this->deltaMinor,
            },
        ];
    }

    /**
     * A mismatch between two amounts, in minor units.
     */
    private static function ofMinor(string $check, string $scope, string $field, int $expectedMinor, int $actualMinor): self
    {
        return new self($check, $scope, $field, (string) $expectedMinor, (string) $actualMinor, $actualMinor - $expectedMinor);
    }
}
