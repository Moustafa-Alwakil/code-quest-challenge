<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Support\Refunds\AllocationLine;
use Carbon\CarbonImmutable;

/**
 * The `earning_allocations` aggregate: what each instructor earned from each
 * recognized period, and the hold on it (R2).
 *
 * The one aggregate that owns a snapshot field outright — `held_minor` is the
 * sum of rows here, and `ledger:verify` recomputes it from nowhere else.
 */
interface EarningAllocationServiceContract
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Writes one recognized period's allocations in a single statement.
     *
     * One multi-row `insertOrIgnore`, not a chunk loop: a period's allocations
     * are bounded by the instructors one subscription engaged with, so a second
     * chunk is a branch no run could take. Chunking belongs where the row count
     * is genuinely unbounded — across periods, not within one.
     *
     * @param  array<int, int> $sharesByInstructor  instructor id => amount in minor units, already non-zero
     * @param  array<int, int> $weightsByInstructor instructor id => the engagement units the share came from
     * @return int             rows written — 0 when this period was already allocated
     */
    public function insertFor(int $periodId, string $currency, CarbonImmutable $availableAt, array $sharesByInstructor, array $weightsByInstructor): int;

    /**
     * The next batch of allocations whose hold has expired, locked for update.
     *
     * `FOR UPDATE` is what stops two concurrent sweeps releasing the same row
     * twice — but it is not what makes the sweep correct: `released_at IS NULL`
     * does that, since a released row drops out of the predicate whatever else
     * is happening. The lock saves the wasted work, the WHERE saves the money.
     *
     * @return list<int>
     */
    public function lockMaturedIds(CarbonImmutable $asOf, int $limit): array;

    /**
     * @param  list<int> $ids
     * @return int       rows released by this call
     */
    public function markReleased(array $ids, CarbonImmutable $releasedAt): int;

    /**
     * What a set of allocations is worth per instructor, ascending by
     * instructor id — the order the snapshot rows must be touched in.
     *
     * @param  list<int>       $ids
     * @return array<int, int> instructor id => total minor units
     */
    public function totalsByInstructor(array $ids): array;

    /**
     * `held_minor` for every instructor, recomputed from the rows that define
     * it: allocated, not yet released, not clawed back (R2).
     *
     * Grouped in MySQL rather than scanned in PHP — at production scale the
     * unreleased set is small and the maturation index covers the predicate,
     * while the row count behind it is not bounded by anything.
     *
     * @return array<int, int> instructor id => held minor units
     */
    public function heldTotals(?int $instructorId = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array;

    /**
     * Σ allocated per period, for the check that `platform + Σ allocations`
     * comes back to the period's gross (invariant I6).
     *
     * Keyset by period id so a verification run over a large table stays flat
     * in memory. A clawed-back allocation still counts: the reversal is a
     * ledger fact of its own, and the recognition it reverses was still exactly
     * this size.
     *
     * @param  list<int>       $periodIds
     * @return array<int, int> accrual period id => total allocated minor units
     */
    public function allocatedTotalsForPeriods(array $periodIds): array;

    /**
     * Every allocation belonging to these periods, for a clawback to reverse
     * exactly (F09).
     *
     * Amounts come back as they were written, never recomputed: running the
     * largest-remainder split again over the same weights could place a
     * piastre differently and would create or destroy money the ledger has
     * already recorded.
     *
     * Already-clawed-back rows are excluded — one refund per subscription means
     * this cannot normally happen, and reversing a reversal would double the
     * debt if it ever did.
     *
     * @param  list<int>            $periodIds
     * @return list<AllocationLine> ascending by instructor id, the lock order
     */
    public function linesForPeriods(array $periodIds): array;

    /**
     * Marks allocations reversed by a refund (F09).
     *
     * Set on released allocations as well as held ones, which reads further
     * than F09's prose: `clawed_back_at` is the record that this earning was
     * taken back, and an auditor asking "which of this instructor\'s earnings
     * were reversed" should not get a different answer depending on whether the
     * hold had expired. The held recomputation is unaffected either way, since
     * a released row is already outside it.
     *
     * @param  list<int> $ids
     * @return int       rows reversed by this call
     */
    public function markClawedBack(array $ids, CarbonImmutable $clawedBackAt): int;
}
