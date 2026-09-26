<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Refunds\AllocationLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * The `earning_allocations` aggregate: what each instructor earned from each
 * recognized period, and the hold on it (D-2, D-6, F05).
 *
 * The hold is deliberately not a ledger fact (R2). That makes this table the
 * source of truth for one snapshot field — `held_minor` — and
 * `ledger:verify` recomputes it from here, so a wrong row is a red run rather
 * than silent drift.
 *
 * Every write is idempotent by construction: the insert is an `insertOrIgnore`
 * against UNIQUE `(accrual_period_id, instructor_id)`, and the release is a
 * conditional `UPDATE` whose WHERE a released row no longer matches.
 */
final class EarningAllocationService
{
    private const DEFAULT_CHUNK_SIZE = 1000;

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
    public function insertFor(
        int $periodId,
        string $currency,
        CarbonImmutable $availableAt,
        array $sharesByInstructor,
        array $weightsByInstructor,
    ): int {
        if ($sharesByInstructor === []) {
            return 0;
        }

        $rows = [];

        foreach ($sharesByInstructor as $instructorId => $amountMinor) {
            $rows[] = [
                'accrual_period_id' => $periodId,
                'instructor_id' => $instructorId,
                'weight_units' => $weightsByInstructor[$instructorId] ?? 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'available_at' => $availableAt,
            ];
        }

        return DB::table('earning_allocations')->insertOrIgnore($rows);
    }

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
    public function lockMaturedIds(CarbonImmutable $asOf, int $limit): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('earning_allocations')
            ->select('id')
            ->whereNull('released_at')
            ->whereNull('clawed_back_at')
            ->where('available_at', '<=', $asOf)
            ->orderBy('id')
            ->limit($limit)
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => self::asInt($id))
            ->all();

        return $ids;
    }

    /**
     * @param  list<int> $ids
     * @return int       rows released by this call
     */
    public function markReleased(array $ids, CarbonImmutable $releasedAt): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('earning_allocations')
            ->whereIn('id', $ids)
            ->whereNull('released_at')
            ->update(['released_at' => $releasedAt]);
    }

    /**
     * What a set of allocations is worth per instructor, ascending by
     * instructor id — the order the snapshot rows must be touched in.
     *
     * @param  list<int>       $ids
     * @return array<int, int> instructor id => total minor units
     */
    public function totalsByInstructor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $totals = [];

        $rows = DB::table('earning_allocations')
            ->selectRaw('instructor_id, sum(amount_minor) as total_minor')
            ->whereIn('id', $ids)
            ->groupBy('instructor_id')
            ->orderBy('instructor_id')
            ->get();

        foreach ($rows as $row) {
            $totals[self::asInt($row->instructor_id)] = self::asInt($row->total_minor);
        }

        return $totals;
    }

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
    public function heldTotals(?int $instructorId = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $totals = [];
        $lastInstructorId = 0;

        while (true) {
            $rows = DB::table('earning_allocations')
                ->selectRaw('instructor_id, sum(amount_minor) as held_minor')
                ->whereNull('released_at')
                ->whereNull('clawed_back_at')
                ->when($instructorId !== null, fn ($query) => $query->where('instructor_id', $instructorId))
                ->where('instructor_id', '>', $lastInstructorId)
                ->groupBy('instructor_id')
                ->orderBy('instructor_id')
                ->limit($chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                return $totals;
            }

            foreach ($rows as $row) {
                $lastInstructorId = self::asInt($row->instructor_id);
                $totals[$lastInstructorId] = self::asInt($row->held_minor);
            }
        }
    }

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
    public function allocatedTotalsForPeriods(array $periodIds): array
    {
        if ($periodIds === []) {
            return [];
        }

        $totals = [];

        $rows = DB::table('earning_allocations')
            ->selectRaw('accrual_period_id, sum(amount_minor) as total_minor')
            ->whereIn('accrual_period_id', $periodIds)
            ->groupBy('accrual_period_id')
            ->get();

        foreach ($rows as $row) {
            $totals[self::asInt($row->accrual_period_id)] = self::asInt($row->total_minor);
        }

        return $totals;
    }

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
    public function linesForPeriods(array $periodIds): array
    {
        if ($periodIds === []) {
            return [];
        }

        $lines = [];

        $rows = DB::table('earning_allocations')
            ->whereIn('accrual_period_id', $periodIds)
            ->whereNull('clawed_back_at')
            ->orderBy('instructor_id')
            ->orderBy('id')
            ->get(['id', 'instructor_id', 'amount_minor', 'released_at']);

        foreach ($rows as $row) {
            $lines[] = new AllocationLine(
                self::asInt($row->id),
                self::asInt($row->instructor_id),
                self::asInt($row->amount_minor),
                $row->released_at !== null,
            );
        }

        return $lines;
    }

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
    public function markClawedBack(array $ids, CarbonImmutable $clawedBackAt): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('earning_allocations')
            ->whereIn('id', $ids)
            ->whereNull('clawed_back_at')
            ->update(['clawed_back_at' => $clawedBackAt]);
    }

    /**
     * A raw aggregate comes back untyped — MySQL hands SUM() over as a string.
     * Narrowing it loudly beats trusting a cast: if the driver ever returns
     * something else, the run fails instead of silently reading 0.
     */
    private static function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Expected a numeric aggregate from earning_allocations, got '.get_debug_type($value).'.');
        }

        return (int) $value;
    }
}
