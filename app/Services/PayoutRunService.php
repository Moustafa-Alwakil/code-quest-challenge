<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use App\Support\Payouts\PayoutRunSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * The payout-run aggregate: runs and the items inside them (F06).
 *
 * One aggregate, not two — an item has no meaning outside its run, and every
 * write that creates one also updates the run's tally in the same transaction
 * the Action owns.
 *
 * Both of the writes here are idempotent by construction rather than by
 * checking first: `insertOrIgnore` against UNIQUE `run_key` and against UNIQUE
 * `(payout_run_id, instructor_id)`. A concurrent invocation of the same run
 * loses the insert and reads the winner's row, which is the same path a
 * sequential re-run takes.
 */
final class PayoutRunService
{
    /**
     * Creates the run for this key, or returns nothing if it already exists.
     *
     * `insertOrIgnore` rather than `firstOrCreate`: the latter is a SELECT then
     * an INSERT, and two processes can both pass the SELECT. Here the database
     * decides, and the loser simply reads the row the winner wrote.
     *
     * @return int rows written — 0 when this key was already on file
     */
    public function createIfAbsent(string $runKey, CarbonImmutable $scheduledFor, CarbonImmutable $startedAt, string $currency): int
    {
        return DB::table('payout_runs')->insertOrIgnore([
            'run_key' => $runKey,
            'scheduled_for' => $scheduledFor->toDateString(),
            'status' => PayoutRunStatus::OPEN->value,
            'currency' => $currency,
            'started_at' => $startedAt,
        ]);
    }

    public function findByKey(string $runKey): ?PayoutRunSnapshot
    {
        return self::snapshot(PayoutRun::query()->where('run_key', $runKey)->first());
    }

    public function find(int $runId): ?PayoutRunSnapshot
    {
        return self::snapshot(PayoutRun::query()->find($runId));
    }

    /**
     * An instructor's balance row, locked for the duration of the caller's
     * transaction (F06 step 1).
     *
     * The lock is what serializes a reservation against a concurrent
     * recognition or clawback for that instructor: without it, two
     * transactions could both read the same `available_minor` and reserve it
     * twice over. Returns the amount available, or null when the instructor
     * has no snapshot row at all.
     */
    public function lockAvailableBalance(int $instructorId): ?int
    {
        /**
         * `value()` still issues the locking SELECT; it just narrows the result
         * to the one column. `available_minor` is NOT NULL, so a null here can
         * only mean the instructor has no snapshot row at all.
         */
        $available = DB::table('instructor_balances')
            ->where('instructor_id', $instructorId)
            ->lockForUpdate()
            ->value('available_minor');

        return $available === null ? null : self::asInt($available);
    }

    /**
     * Creates one instructor's item, or returns null when this run already has
     * one for them.
     *
     * The null is not an error: it is a second invocation of the same run key,
     * or a concurrent one, finding the work already done.
     *
     * @return array{0: int, 1: string}|null the item id and its idempotency key
     */
    public function createReservedItem(int $runId, int $instructorId, int $amountMinor, string $currency): ?array
    {
        $idempotencyKey = (string) Str::uuid();

        $written = DB::table('payout_items')->insertOrIgnore([
            'payout_run_id' => $runId,
            'instructor_id' => $instructorId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => PayoutItemStatus::RESERVED->value,
            'idempotency_key' => $idempotencyKey,
            /**
             * Stamped by the application, not by `useCurrent()`. F08's stranded
             * sweep asks "has this sat here for half an hour", and comparing an
             * application instant against a column the database filled from its
             * own clock is two clocks deciding one money question. The column
             * keeps its default for any row written outside this method.
             */
            'created_at' => CarbonImmutable::now(),
        ]);

        if ($written === 0) {
            return null;
        }

        $id = DB::table('payout_items')
            ->where('payout_run_id', $runId)
            ->where('instructor_id', $instructorId)
            ->value('id');

        return [self::asInt($id), $idempotencyKey];
    }

    /**
     * Folds one new item into the run's tally.
     *
     * An atomic `UPDATE ... SET x = x + ?`, never a read-modify-write: several
     * reservations commit concurrently and each must be counted.
     */
    public function addToTally(int $runId, int $amountMinor): void
    {
        DB::table('payout_runs')
            ->where('id', $runId)
            ->incrementEach([
                'item_count' => 1,
                'total_minor' => $amountMinor,
            ]);
    }

    /**
     * The next page of instructors worth paying, by keyset.
     *
     * `available_minor >= min` filters in SQL, so a run over half a million
     * balances reads only the rows it might reserve. Ascending instructor id is
     * both the keyset order and the lock order that keeps concurrent runs from
     * deadlocking each other.
     *
     * @return array<int, int> instructor id => available minor units, ascending
     */
    public function payableBalances(int $minimumAmountMinor, int $afterInstructorId, int $limit): array
    {
        $balances = [];

        $rows = DB::table('instructor_balances')
            ->where('available_minor', '>=', max($minimumAmountMinor, 1))
            ->where('instructor_id', '>', $afterInstructorId)
            ->orderBy('instructor_id')
            ->limit($limit)
            ->get(['instructor_id', 'available_minor']);

        foreach ($rows as $row) {
            $balances[self::asInt($row->instructor_id)] = self::asInt($row->available_minor);
        }

        return $balances;
    }

    /**
     * Every item of this run a worker may still be given — including ones left
     * `reserved` by an invocation that crashed before dispatching them.
     *
     * @return list<int>
     */
    public function dispatchableItemIds(int $runId): array
    {
        /** @var list<int> $ids */
        $ids = PayoutItem::query()
            ->where('payout_run_id', $runId)
            ->where('status', PayoutItemStatus::RESERVED)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return $ids;
    }

    /**
     * How many of the run's items sit in each status, for finalization.
     *
     * @return array<string, int>
     */
    public function itemStatusCounts(int $runId): array
    {
        $counts = [];

        $rows = DB::table('payout_items')
            ->selectRaw('status, count(*) as item_count')
            ->where('payout_run_id', $runId)
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $counts[self::asString($row->status)] = self::asInt($row->item_count);
        }

        return $counts;
    }

    /**
     * Advances a run's status, only from the status the caller expects.
     *
     * A compare-and-swap like every other transition in this system: a
     * finalization racing a second invocation cannot move a run backwards, and
     * the loser's `affected === 0` tells it so.
     *
     * @return bool true when this call made the transition
     */
    public function transition(int $runId, PayoutRunStatus $from, PayoutRunStatus $to, ?CarbonImmutable $finishedAt = null): bool
    {
        $values = ['status' => $to->value];

        if ($finishedAt instanceof CarbonImmutable) {
            $values['finished_at'] = $finishedAt;
        }

        $moved = DB::table('payout_runs')
            ->where('id', $runId)
            ->where('status', $from->value)
            ->update($values);

        return $moved === 1;
    }

    private static function snapshot(?PayoutRun $run): ?PayoutRunSnapshot
    {
        return $run === null ? null : new PayoutRunSnapshot(
            $run->id,
            $run->run_key,
            $run->status,
            $run->item_count,
            $run->total_minor,
        );
    }

    private static function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Expected a numeric column from the payout tables, got '.get_debug_type($value).'.');
        }

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a string column from the payout tables, got '.get_debug_type($value).'.');
        }

        return $value;
    }
}
