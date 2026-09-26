<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\InstructorBalance;
use App\Support\Ledger\BalanceDelta;
use Illuminate\Support\LazyCollection;

/**
 * The `instructor_balances` snapshot: the cache `ledger:verify` proves.
 *
 * Two halves that must never drift — `applyDeltas()` writes the snapshot, and
 * `recomputeFromLedger()` derives what it should have been. Anything that
 * implements this owes both, and owes them from the same source of truth.
 */
interface InstructorBalanceServiceContract
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Folds the deltas of one posting into the snapshot.
     *
     * Deltas for the same instructor are merged first, so the row is touched
     * once, and rows are then touched in ascending instructor id — the ordering
     * that stops two concurrent multi-instructor postings deadlocking each
     * other. Missing rows are created by an `insertOrIgnore` of zeroes, which
     * is safe to race: whoever loses still increments the winner's row.
     */
    public function applyDeltas(string $currency, int $lastLedgerEntryId, BalanceDelta ...$deltas): void;

    /**
     * The snapshot rows, streamed by keyset (`WHERE instructor_id > ?`) rather
     * than OFFSET, so a verification run over a large table stays flat in
     * memory and does not skip rows that shift between pages.
     *
     * @return LazyCollection<int, InstructorBalance>
     */
    public function snapshots(?int $instructorId = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): LazyCollection;

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
     * `held` is the one field the ledger cannot answer for: the hold lives on
     * `earning_allocations` rows and posts no entries of its own (R2). It is
     * the sum of allocations with neither `released_at` nor `clawed_back_at`
     * set, and `available` — the owed balance of `instructor_payable[i]` less
     * what is still held — follows from it. This is the recomputation R20
     * promised F05 would supply.
     *
     * @return array<int, array{earned: int, clawed_back: int, held: int, available: int, reserved: int, paid: int, currencies: list<string>}>
     */
    public function recomputeFromLedger(?int $instructorId = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array;
}
