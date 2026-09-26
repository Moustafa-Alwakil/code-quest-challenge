<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Enums\RefundType;
use App\Models\InstructorBalance;
use App\Services\Contracts\AccrualServiceContract;
use App\Services\Contracts\EarningAllocationServiceContract;
use App\Services\Contracts\InstructorBalanceServiceContract;
use App\Services\Contracts\LedgerServiceContract;
use App\Services\Contracts\LedgerVerificationServiceContract;
use App\Services\Contracts\PayoutItemServiceContract;
use App\Services\Contracts\RefundServiceContract;
use App\Services\Contracts\SubscriptionServiceContract;
use App\Support\Ledger\BalanceTotals;
use App\Support\Ledger\LedgerMismatch;
use App\Support\Ledger\LedgerVerificationResult;

/**
 * Proves that the snapshot and the ledger agree — or reports exactly where they
 * do not (F03, invariants I1-I4).
 *
 * The snapshot exists for O(1) reads, which makes it a cache; a cache nobody
 * checks is a second source of truth waiting to drift. This is the check.
 *
 * Six checks, covering invariants I1-I6. Check 3 covers `currency` as well as
 * the six money columns (R21), and its `held_minor` comparison is only
 * meaningful from F05 onward, when `earning_allocations` gives the hold
 * somewhere to be recomputed from (R2, R20).
 *
 * Checks 5 to 8 are the subscription-, period- and payout-level statements the
 * per-instructor checks cannot make: that a delivered term's liability returned
 * to exactly zero, that a recognized period gave away precisely its gross, that
 * a term's price is still fully accounted for after a refund, and that a
 * succeeded payout was reserved once and settled once. All four are skipped
 * under `--instructor=`, like checks 1 and 2, because none is a fact about one
 * instructor.
 */
final class LedgerVerificationService implements LedgerVerificationServiceContract
{
    public function __construct(
        private LedgerServiceContract $ledger,
        private InstructorBalanceServiceContract $balances,
        private AccrualServiceContract $accrual,
        private EarningAllocationServiceContract $allocations,
        private SubscriptionServiceContract $subscriptions,
        private RefundServiceContract $refunds,
        private PayoutItemServiceContract $payoutItems,
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

            $expected = $recomputed[$id] ?? BalanceTotals::zero();
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

        if ($instructorId === null) {
            foreach ($this->deferredRevenueMismatches($chunkSize) as $mismatch) {
                $mismatches[] = $mismatch;

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
                }
            }

            foreach ($this->periodSplitMismatches($chunkSize) as $mismatch) {
                $mismatches[] = $mismatch;

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
                }
            }

            foreach ($this->termTotalMismatches($chunkSize) as $mismatch) {
                $mismatches[] = $mismatch;

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
                }
            }

            foreach ($this->payoutPairingMismatches($chunkSize) as $mismatch) {
                $mismatches[] = $mismatch;

                if ($failFast) {
                    return $this->result($mismatches, $instructorId, $transactionsChecked, $instructorsChecked, true);
                }
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
     * Check 7 (I8) — a term's price is still fully accounted for, refunds
     * included.
     *
     * Freshly scheduled, this is simply `Σ period gross === price`: the
     * largest-remainder split (D-5) gave every piastre to some period. What
     * makes it worth a check is what refunds do to it, and the two refund types
     * do different things:
     *
     * - **Cancelling** a period leaves its gross in the table untouched, so the
     *   sum is unchanged and nothing needs adding back.
     * - **Truncating** one lowers its gross by the unused days, and that money
     *   left as part of the refund. The amount removed is exactly
     *   `refund − Σ cancelled gross`, which is why the refund has to enter the
     *   identity for a pro-rata term.
     *
     * The two sides come from different tables — the schedule and the refunds —
     * so agreement means the truncation gave back precisely what it removed.
     *
     * @return list<LedgerMismatch>
     */
    private function termTotalMismatches(int $chunkSize): array
    {
        $mismatches = [];
        $afterId = 0;

        while (true) {
            $subscriptionIds = $this->subscriptions->idsAfter($afterId, $chunkSize);

            if ($subscriptionIds === []) {
                return $mismatches;
            }

            $afterId = $subscriptionIds[count($subscriptionIds) - 1];

            $prices = $this->subscriptions->pricesFor($subscriptionIds);
            $gross = $this->accrual->grossTotalsFor($subscriptionIds);
            $refunds = $this->refunds->forSubscriptions($subscriptionIds);

            foreach ($subscriptionIds as $subscriptionId) {
                /**
                 * A term with no periods at all was never scheduled, so there
                 * is no split for this check to have an opinion about — the
                 * same scoping R30 applies to check 5. It cannot hide a real
                 * failure: `AccrualSchedule` asserts `Σ gross === price` before
                 * a row is written and `scheduleFor()` shares the subscription's
                 * transaction, so a real term without its schedule cannot
                 * commit. What it does exempt is a factory fixture, which F02
                 * says has no ledger behind it by design.
                 */
                if (! isset($gross['all'][$subscriptionId])) {
                    continue;
                }

                $price = $prices[$subscriptionId] ?? 0;
                $accountedFor = $gross['all'][$subscriptionId];

                $refund = $refunds[$subscriptionId] ?? null;

                if ($refund !== null && $refund['type'] === RefundType::PRORATA) {
                    $accountedFor += $refund['amount'] - ($gross['cancelled'][$subscriptionId] ?? 0);
                }

                if ($accountedFor !== $price) {
                    $mismatches[] = LedgerMismatch::termTotal($subscriptionId, $price, $accountedFor);
                }
            }
        }
    }

    /**
     * Check 8 (I7, ledger half) — a succeeded payout was reserved once and
     * settled once.
     *
     * Two legs of each, because every posting here is two-sided. The unique key
     * already makes a duplicate impossible, so what this catches is an absence:
     * an item marked succeeded that nothing ever reserved, or whose settlement
     * never landed — either of which leaves money in `provider_in_transit`
     * that the snapshot thinks was paid.
     *
     * I7's other half, that the provider moved the money exactly once, is not
     * knowable from the ledger. The chaos test asserts it against the provider's
     * own records.
     *
     * @return list<LedgerMismatch>
     */
    private function payoutPairingMismatches(int $chunkSize): array
    {
        $mismatches = [];
        $afterId = 0;

        while (true) {
            $itemIds = $this->payoutItems->succeededItemIdsAfter($afterId, $chunkSize);

            if ($itemIds === []) {
                return $mismatches;
            }

            $afterId = $itemIds[count($itemIds) - 1];

            $counts = $this->ledger->payoutLegCounts($itemIds);

            foreach ($itemIds as $itemId) {
                foreach ([LedgerEntryType::PAYOUT_RESERVED, LedgerEntryType::PAYOUT_SETTLED] as $entryType) {
                    $legs = $counts[$itemId][$entryType->value] ?? 0;

                    if ($legs !== 2) {
                        $mismatches[] = LedgerMismatch::payoutPairing($itemId, $entryType->value.' legs', 2, $legs);
                    }
                }
            }
        }
    }

    /**
     * Check 5 (I5) — every subscription's liability against the time it has not
     * delivered yet.
     *
     * The sharper form of "deferred revenue returns to zero". Rather than
     * waiting until the term is over to compare against 0, this asserts the
     * liability equals Σ gross of the periods still `scheduled` *at every
     * moment*: recognition removes a period from that sum and the same amount
     * from that balance in one transaction, so the two can only disagree if a
     * recognition posted a different gross than it recognized, or posted twice.
     *
     * A negative balance is caught by the same comparison — a sum of gross is
     * never negative — so the "never negative" half of I5 needs no separate
     * pass.
     *
     * Iterated over subscriptions rather than over `deferred_revenue` accounts,
     * because the invariant is a statement about a term. An account keyed to no
     * subscription is outside what this check can say anything about.
     *
     * @return list<LedgerMismatch>
     */
    private function deferredRevenueMismatches(int $chunkSize): array
    {
        $mismatches = [];
        $afterId = 0;

        while (true) {
            $subscriptionIds = $this->subscriptions->idsAfter($afterId, $chunkSize);

            if ($subscriptionIds === []) {
                return $mismatches;
            }

            $afterId = $subscriptionIds[count($subscriptionIds) - 1];

            $owed = $this->ledger->deferredRevenueOwedFor($subscriptionIds);
            $undelivered = $this->accrual->unrecognizedGrossFor($subscriptionIds);

            foreach ($subscriptionIds as $subscriptionId) {
                $expected = $undelivered[$subscriptionId] ?? 0;
                $actual = $owed[$subscriptionId] ?? 0;

                if ($expected !== $actual) {
                    $mismatches[] = LedgerMismatch::deferredRevenue($subscriptionId, $expected, $actual);
                }
            }
        }
    }

    /**
     * Check 6 (I6) — `platform + Σ allocations === gross`, per recognized
     * period.
     *
     * The one check that looks at how a period was *divided* rather than at
     * what the division summed to overall. A dropped allocation row leaves the
     * ledger balanced and every snapshot consistent — only this notices.
     *
     * @return list<LedgerMismatch>
     */
    private function periodSplitMismatches(int $chunkSize): array
    {
        $mismatches = [];
        $afterPeriodId = 0;

        while (true) {
            $splits = $this->accrual->recognizedSplits($afterPeriodId, $chunkSize);

            if ($splits === []) {
                return $mismatches;
            }

            $allocated = $this->allocations->allocatedTotalsForPeriods(array_keys($splits));

            foreach ($splits as $periodId => $split) {
                $afterPeriodId = $periodId;
                $accountedFor = $split['platform'] + ($allocated[$periodId] ?? 0);

                if ($accountedFor !== $split['gross']) {
                    $mismatches[] = LedgerMismatch::periodSplit($periodId, $split['gross'], $accountedFor);
                }
            }
        }
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
