<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Support\Ledger\BalanceDelta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The `instructor_balances` aggregate: the snapshot, and the recomputation that
 * proves it (F03).
 *
 * Every write here is an atomic `UPDATE ... SET x = x + ?`. Nothing reads a
 * balance into PHP, changes it and saves it back: that pattern silently drops a
 * concurrent posting, and at this layer a dropped posting is money.
 */
final class InstructorBalanceService
{
    /**
     * Rows are created on first posting, so a run that only reads may find none.
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * What the ledger says about an instructor it has never mentioned.
     *
     * @return array{earned: int, clawed_back: int, held: int, available: int, reserved: int, paid: int, currencies: list<string>}
     */
    public static function zeroTotals(): array
    {
        return [
            'earned' => 0,
            'clawed_back' => 0,
            'held' => 0,
            'available' => 0,
            'reserved' => 0,
            'paid' => 0,
            'currencies' => [],
        ];
    }

    /**
     * Folds the deltas of one posting into the snapshot.
     *
     * Deltas for the same instructor are merged first, so the row is touched
     * once, and rows are then touched in ascending instructor id — the ordering
     * that stops two concurrent multi-instructor postings deadlocking each
     * other. Missing rows are created by an `insertOrIgnore` of zeroes, which
     * is safe to race: whoever loses still increments the winner's row.
     */
    public function applyDeltas(string $currency, int $lastLedgerEntryId, BalanceDelta ...$deltas): void
    {
        /** @var array<int, BalanceDelta> $merged */
        $merged = [];

        foreach ($deltas as $delta) {
            $merged[$delta->instructorId] = isset($merged[$delta->instructorId])
                ? $merged[$delta->instructorId]->plus($delta)
                : $delta;
        }

        if ($merged === []) {
            return;
        }

        ksort($merged);

        $this->ensureRowsExist($currency, array_keys($merged));

        foreach ($merged as $instructorId => $delta) {
            DB::table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->incrementEach(
                    [
                        'earned_minor' => $delta->earned,
                        'clawed_back_minor' => $delta->clawedBack,
                        'held_minor' => $delta->held,
                        'available_minor' => $delta->available,
                        'reserved_minor' => $delta->reserved,
                        'paid_minor' => $delta->paid,
                    ],
                    [
                        'last_ledger_entry_id' => DB::raw('greatest(last_ledger_entry_id, '.$lastLedgerEntryId.')'),
                    ],
                );
        }
    }

    /**
     * The snapshot rows, streamed by keyset (`WHERE instructor_id > ?`) rather
     * than OFFSET, so a verification run over a large table stays flat in
     * memory and does not skip rows that shift between pages.
     *
     * @return LazyCollection<int, InstructorBalance>
     */
    public function snapshots(?int $instructorId = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): LazyCollection
    {
        return InstructorBalance::query()
            ->when($instructorId !== null, fn ($query) => $query->where('instructor_id', $instructorId))
            ->lazyById($chunkSize, 'instructor_id');
    }

    /**
     * Recomputes every balance field from the ledger — the source of truth the
     * snapshot is a cache of.
     *
     * Liabilities are credit-normal, so an account's *owed* balance is the
     * negated sum of its entries; the snapshot stores those as positive numbers.
     *
     * `currency` is recomputed too (R21): it is a snapshot field like any other,
     * and the returned list is every distinct currency that instructor has
     * entries in — empty when they have none, more than one when something has
     * gone badly wrong.
     *
     * `held` recomputes to 0 here, which is correct today: the hold lives on
     * `earning_allocations` rows (R2), and that table arrives with F05. When it
     * does, `held` becomes the sum of allocations with neither `released_at`
     * nor `clawed_back_at` set, and `available` follows it. `tests/Feature/
     * Ledger/HeldBalanceGapTest.php` goes red on that day (R20).
     *
     * @return array<int, array{earned: int, clawed_back: int, held: int, available: int, reserved: int, paid: int, currencies: list<string>}>
     */
    public function recomputeFromLedger(?int $instructorId = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        /** @var array<int, array{earned: int, clawed_back: int, held: int, available: int, reserved: int, paid: int, currencies: list<string>}> $totals */
        $totals = [];

        /** @var array<int, int> $payableOwed */
        $payableOwed = [];

        /** @var array<int, array<string, true>> $currencies */
        $currencies = [];

        $lastId = 0;

        while (true) {
            $entries = LedgerEntry::query()
                ->select(['id', 'account_type', 'account_id', 'amount_minor', 'entry_type', 'currency'])
                ->whereIn('account_type', LedgerAccountType::instructorKeyed())
                ->when($instructorId !== null, fn ($query) => $query->where('account_id', $instructorId))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunkSize)
                ->get();

            if ($entries->isEmpty()) {
                break;
            }

            foreach ($entries as $entry) {
                $instructor = $entry->account_id;
                $amount = $entry->amount_minor;

                $lastId = $entry->id;

                $totals[$instructor] ??= self::zeroTotals();
                $payableOwed[$instructor] ??= 0;
                $currencies[$instructor][$entry->currency] = true;

                if ($entry->account_type === LedgerAccountType::INSTRUCTOR_PAYABLE) {
                    $payableOwed[$instructor] -= $amount;

                    if ($entry->entry_type === LedgerEntryType::PERIOD_RECOGNIZED) {
                        $totals[$instructor]['earned'] -= $amount;
                    }

                    if ($entry->entry_type === LedgerEntryType::REFUND_CLAWBACK) {
                        $totals[$instructor]['clawed_back'] += $amount;
                    }

                    continue;
                }

                $totals[$instructor]['reserved'] -= $amount;

                if ($entry->entry_type === LedgerEntryType::PAYOUT_SETTLED) {
                    $totals[$instructor]['paid'] += $amount;
                }
            }
        }

        foreach ($totals as $instructor => $fields) {
            $totals[$instructor]['available'] = $payableOwed[$instructor] - $fields['held'];

            $distinct = array_keys($currencies[$instructor]);
            sort($distinct);
            $totals[$instructor]['currencies'] = $distinct;
        }

        return $totals;
    }

    /**
     * Creates any missing snapshot rows at zero, in the order given.
     *
     * `insertOrIgnore`, so two postings racing to create the same row both
     * succeed: the loser's insert is ignored and its increment still lands.
     *
     * @param list<int> $instructorIds ascending
     */
    private function ensureRowsExist(string $currency, array $instructorIds): void
    {
        $rows = array_map(static fn (int $instructorId): array => [
            'instructor_id' => $instructorId,
            'currency' => $currency,
        ], $instructorIds);

        DB::table('instructor_balances')->insertOrIgnore($rows);
    }
}
