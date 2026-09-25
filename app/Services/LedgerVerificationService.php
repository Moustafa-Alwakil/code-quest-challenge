<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstructorBalance;
use App\Support\Ledger\LedgerMismatch;
use App\Support\Ledger\LedgerVerificationResult;

/**
 * Proves that the snapshot and the ledger agree — or reports exactly where they
 * do not (F03, invariants I1-I4).
 *
 * The snapshot exists for O(1) reads, which makes it a cache; a cache nobody
 * checks is a second source of truth waiting to drift. This is the check.
 *
 * Scope today is checks 1-4, with check 3 covering `currency` as well as the six
 * money columns (R21). Check 5 — `deferred_revenue[sub]` returning to exactly 0
 * once every period is recognized or cancelled — needs the subscription periods
 * that arrive with F04, and `held` recomputes to 0 until F05 brings
 * `earning_allocations` (R2). Both are follow-ups, not omissions.
 */
final class LedgerVerificationService
{
    private const DEFAULT_CHUNK_SIZE = 1000;

    public function __construct(
        private LedgerService $ledger,
        private InstructorBalanceService $balances,
    ) {}

    /**
     * Runs the four checks and returns everything that disagreed.
     *
     * Restricting to one instructor skips checks 1 and 2 by design: a single
     * instructor's legs are one side of transactions whose other side sits on
     * platform accounts, so their sum is *supposed* to be non-zero. Only the
     * whole ledger balances.
     */
    public function verify(
        ?int $instructorId = null,
        bool $failFast = false,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ): LedgerVerificationResult {
        /** @var list<LedgerMismatch> $mismatches */
        $mismatches = [];
        $transactionsChecked = 0;

        if ($instructorId === null) {
            $ledgerSum = $this->ledger->sumOfAllEntries();

            if ($ledgerSum !== 0) {
                $mismatches[] = LedgerMismatch::ledgerSum($ledgerSum);

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, 0, 0, true);
                }
            }

            $transactionsChecked = $this->ledger->transactionCount();

            foreach ($this->ledger->unbalancedTransactions() as $transactionUuid => $deltaMinor) {
                $mismatches[] = LedgerMismatch::transactionSum($transactionUuid, $deltaMinor);

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, $transactionsChecked, 0, true);
                }
            }
        }

        $recomputed = $this->balances->recomputeFromLedger($instructorId, $chunkSize);
        $instructorsChecked = 0;

        foreach ($this->balances->snapshots($instructorId, $chunkSize) as $snapshot) {
            $instructorsChecked++;
            $id = $snapshot->instructor_id;

            $expected = $recomputed[$id] ?? InstructorBalanceService::zeroTotals();
            unset($recomputed[$id]);

            $stored = self::storedColumns($snapshot);

            foreach (self::comparisons($expected) as $column => $expectedMinor) {
                $actual = $stored[$column];

                if ($actual !== $expectedMinor) {
                    $mismatches[] = LedgerMismatch::snapshotField($id, $column, $expectedMinor, $actual);

                    if ($failFast) {
                        return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
                    }
                }
            }

            $currencyMismatch = self::currencyMismatch($id, $snapshot->currency, $expected['currencies']);

            if ($currencyMismatch instanceof LedgerMismatch) {
                $mismatches[] = $currencyMismatch;

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
                }
            }

            $outstanding = $snapshot->outstandingMinor();
            $parts = $snapshot->available_minor + $snapshot->held_minor + $snapshot->reserved_minor;

            if ($outstanding !== $parts) {
                $mismatches[] = LedgerMismatch::outstandingIdentity($id, $outstanding, $parts);

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
                }
            }
        }

        /** The ledger owes someone money that no snapshot row knows about. */
        foreach ($recomputed as $id => $fields) {
            $outstanding = $fields['earned'] - $fields['clawed_back'] - $fields['paid'];

            if ($outstanding === 0 && $fields['available'] === 0 && $fields['reserved'] === 0 && $fields['held'] === 0) {
                continue;
            }

            $mismatches[] = LedgerMismatch::missingSnapshot($id, $outstanding);

            if ($failFast) {
                return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
            }
        }

        return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, false);
    }

    /**
     * Check 3, currency (R21) — the snapshot's currency against the one its
     * entries are actually in.
     *
     * An instructor with no entries is skipped: an all-zero row carries a
     * currency the ledger has nothing to say about yet. Two currencies under
     * one instructor is reported as the divergence it is, rather than being
     * compared against whichever one sorted first.
     *
     * @param list<string> $ledgerCurrencies
     */
    private static function currencyMismatch(int $instructorId, string $stored, array $ledgerCurrencies): ?LedgerMismatch
    {
        if ($ledgerCurrencies === []) {
            return null;
        }

        if (count($ledgerCurrencies) > 1) {
            return LedgerMismatch::currency($instructorId, implode(' + ', $ledgerCurrencies), $stored);
        }

        return $ledgerCurrencies[0] === $stored
            ? null
            : LedgerMismatch::currency($instructorId, $ledgerCurrencies[0], $stored);
    }

    /**
     * Snapshot column to the value the ledger says it should hold.
     *
     * @param  array{earned: int, clawed_back: int, held: int, available: int, reserved: int, paid: int, currencies: list<string>} $expected
     * @return array<string, int>
     */
    private static function comparisons(array $expected): array
    {
        return [
            'earned_minor' => $expected['earned'],
            'clawed_back_minor' => $expected['clawed_back'],
            'held_minor' => $expected['held'],
            'available_minor' => $expected['available'],
            'reserved_minor' => $expected['reserved'],
            'paid_minor' => $expected['paid'],
        ];
    }

    /**
     * The same six columns, as the snapshot currently holds them.
     *
     * @return array<string, int>
     */
    private static function storedColumns(InstructorBalance $snapshot): array
    {
        return [
            'earned_minor' => $snapshot->earned_minor,
            'clawed_back_minor' => $snapshot->clawed_back_minor,
            'held_minor' => $snapshot->held_minor,
            'available_minor' => $snapshot->available_minor,
            'reserved_minor' => $snapshot->reserved_minor,
            'paid_minor' => $snapshot->paid_minor,
        ];
    }

    /**
     * @param list<LedgerMismatch> $mismatches
     */
    private function result(
        array $mismatches,
        ?int $instructorId,
        int $transactionsChecked,
        int $instructorsChecked,
        bool $stoppedEarly,
    ): LedgerVerificationResult {
        return LedgerVerificationResult::of(
            $mismatches,
            $this->ledger->entryCount($instructorId),
            $transactionsChecked,
            $instructorsChecked,
            $stoppedEarly,
        );
    }
}
