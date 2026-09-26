<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\PayoutRunStatus;
use App\Support\Payouts\PayoutRunSnapshot;
use Carbon\CarbonImmutable;

/**
 * The payout-run aggregate: runs, and the items inside them.
 *
 * One aggregate rather than two, because an item has no meaning outside its
 * run. `lockAvailableBalance()` is the odd one out and belongs here anyway: the
 * lock it takes is what makes "reserve what is available" a single decision
 * rather than two reads of the same number.
 */
interface PayoutRunServiceContract
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
    public function createIfAbsent(string $runKey, CarbonImmutable $scheduledFor, CarbonImmutable $startedAt, string $currency): int;

    public function findByKey(string $runKey): ?PayoutRunSnapshot;

    public function find(int $runId): ?PayoutRunSnapshot;

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
    public function lockAvailableBalance(int $instructorId): ?int;

    /**
     * Creates one instructor's item, or returns null when this run already has
     * one for them.
     *
     * The null is not an error: it is a second invocation of the same run key,
     * or a concurrent one, finding the work already done.
     *
     * @return array{0: int, 1: string}|null the item id and its idempotency key
     */
    public function createReservedItem(int $runId, int $instructorId, int $amountMinor, string $currency): ?array;

    /**
     * Folds one new item into the run's tally.
     *
     * An atomic `UPDATE ... SET x = x + ?`, never a read-modify-write: several
     * reservations commit concurrently and each must be counted.
     */
    public function addToTally(int $runId, int $amountMinor): void;

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
    public function payableBalances(int $minimumAmountMinor, int $afterInstructorId, int $limit): array;

    /**
     * Every item of this run a worker may still be given — including ones left
     * `reserved` by an invocation that crashed before dispatching them.
     *
     * @return list<int>
     */
    public function dispatchableItemIds(int $runId): array;

    /**
     * How many of the run's items sit in each status, for finalization.
     *
     * @return array<string, int>
     */
    public function itemStatusCounts(int $runId): array;

    /**
     * Advances a run's status, only from the status the caller expects.
     *
     * A compare-and-swap like every other transition in this system: a
     * finalization racing a second invocation cannot move a run backwards, and
     * the loser's `affected === 0` tells it so.
     *
     * @return bool true when this call made the transition
     */
    public function transition(int $runId, PayoutRunStatus $from, PayoutRunStatus $to, ?CarbonImmutable $finishedAt = null): bool;
}
