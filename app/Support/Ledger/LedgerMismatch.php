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

    public const CHECK_DEFERRED_REVENUE = 'deferred revenue';

    public const CHECK_PERIOD_SPLIT = 'period split';

    public const CHECK_TERM_TOTAL = 'term total';

    public const CHECK_PAYOUT_PAIRING = 'payout pairing';

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
     * Check 5 (I5) — a subscription whose periods are all recognized or
     * cancelled has delivered everything it was paid for, so its liability is
     * back to exactly 0. A *negative* balance means more revenue was recognized
     * than the student ever paid, which no amount of rounding can excuse.
     */
    public static function deferredRevenue(int $subscriptionId, int $expectedMinor, int $actualMinor): self
    {
        return self::ofMinor(
            self::CHECK_DEFERRED_REVENUE,
            "subscription {$subscriptionId}",
            'deferred_revenue owed',
            $expectedMinor,
            $actualMinor,
        );
    }

    /**
     * Check 6 (I6) — a recognized period gave every piastre of its gross to
     * exactly one of the platform or an instructor. This is the check that
     * catches a dropped allocation row or a re-rounded pool.
     */
    public static function periodSplit(int $periodId, int $grossMinor, int $platformPlusAllocatedMinor): self
    {
        return self::ofMinor(
            self::CHECK_PERIOD_SPLIT,
            "accrual period {$periodId}",
            'platform + sum(allocations)',
            $grossMinor,
            $platformPlusAllocatedMinor,
        );
    }

    /**
     * Check 7 (I8) — a term's periods, plus whatever was refunded, add back up
     * to the price the student paid.
     *
     * The refund term is what makes this survive F09: truncating a period
     * lowers Σ gross by exactly the unused part, which is exactly what the
     * refund gave back. Without it the check would go red on every refunded
     * subscription and teach nothing.
     */
    public static function termTotal(int $subscriptionId, int $priceMinor, int $accountedForMinor): self
    {
        return self::ofMinor(
            self::CHECK_TERM_TOTAL,
            "subscription {$subscriptionId}",
            'sum(period gross) + refunded',
            $priceMinor,
            $accountedForMinor,
        );
    }

    /**
     * Check 8 (I7, ledger half) — a succeeded payout item has exactly one
     * `payout_reserved` transaction and exactly one `payout_settled`.
     *
     * The other half of I7 — that the provider moved the money once — is not
     * knowable from here: it lives in the provider's own records, and the suite
     * asserts it through `ScriptedMockProvider::transferCount()`. What the
     * ledger can prove is that we only ever *accounted* for one transfer, which
     * is the half a production verifier could run.
     */
    public static function payoutPairing(int $payoutItemId, string $field, int $expected, int $actual): self
    {
        return self::ofMinor(
            self::CHECK_PAYOUT_PAIRING,
            "payout item {$payoutItemId}",
            $field,
            $expected,
            $actual,
        );
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
