<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Accrual\AccrualSchedule;
use App\Support\Accrual\PeriodForRecognition;
use App\Support\Refunds\PeriodLine;
use Carbon\CarbonImmutable;

/**
 * The `accrual_periods` aggregate: a term's schedule, its recognition, and the
 * refund surgery F09 performs on it.
 *
 * Every method here is a statement about *which* periods, never about what a
 * period is worth — the split itself lives in `App\Support` and arrives already
 * computed. That separation is what lets the schedule be property-tested
 * without a database and the persistence be swapped without touching the maths.
 */
interface AccrualServiceContract
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * One multi-row `insertOrIgnore`, not a chunk loop: a term is at most
     * `AccrualSchedule::MAX_INTERVAL_MONTHS` periods, so a second chunk is a
     * branch no run could ever take. If a later caller inserts periods for many
     * subscriptions in one statement, the chunking belongs in that method, where
     * it can actually iterate.
     *
     * @return int periods written — 0 when the schedule was already on file
     */
    public function scheduleFor(int $subscriptionId, AccrualSchedule $schedule): int;

    /**
     * Writes the schedules of many terms in one statement (F02's `ScaleSeeder`).
     *
     * The single-term `scheduleFor()` deliberately does not chunk, because a
     * term is at most twelve rows. Here the row count is unbounded — it is the
     * number of terms times their periods — so the caller chunks and this takes
     * whatever it is handed.
     *
     * @param  list<array<string, mixed>> $rows
     * @return int                        periods written
     */
    public function insertSchedulesInBulk(array $rows): int;

    /**
     * The next page of periods whose term has closed and which nobody has
     * recognized yet (F05 step 2).
     *
     * Keyset (`WHERE id > ?`), never OFFSET: a sweep over half a million
     * periods must not re-count rows it has already passed, and recognition
     * changes `status` under the cursor, which is exactly the case OFFSET gets
     * wrong. Hits `accrual_periods_recognition_index (status, period_end)`.
     *
     * `period_end` is exclusive, so a period ending on the run date is over on
     * the run date and is due.
     *
     * @return list<int> ascending
     */
    public function dueScheduledPeriodIds(CarbonImmutable $asOf, int $afterId, int $limit): array;

    /**
     * The compare-and-set that claims a period for this run (F05 step 1).
     *
     * This is the whole concurrency story. Two servers sweeping at once both
     * issue this UPDATE; MySQL serializes them on the row, and exactly one sees
     * an affected count of 1. The loser recognizes nothing and writes nothing.
     *
     * There is no intermediate `recognizing` status (R3): this UPDATE and every
     * write that follows share the caller's transaction, so a crash takes the
     * status back with it and the next run picks the period up. A separate
     * state would be stranded by exactly the crash it was invented to survive.
     *
     * @return bool true when this call claimed the period; false when it was
     *              already recognized, or cancelled by a refund
     */
    public function markRecognized(int $periodId, CarbonImmutable $recognizedAt): bool;

    /**
     * What the claimed period is worth, and which window it covers.
     *
     * Read after the CAS, inside the same transaction: before it, the row could
     * still be claimed by someone else, and the currency comes from the
     * subscription's snapshotted price rather than from config, so a plan
     * repriced in another currency could never rewrite an existing term.
     */
    public function findForRecognition(int $periodId): ?PeriodForRecognition;

    /**
     * Records how the gross was divided (F05 step 8).
     *
     * A separate statement from the CAS because it carries different
     * information: the CAS decides *who* recognizes the period, this records
     * *what they decided*. Both commit together, so a period can never be
     * `recognized` with a null split.
     */
    public function storeSplit(int $periodId, int $poolMinor, int $platformMinor): void;

    /**
     * Every period of one term, in sequence, as refund planning sees them (F09).
     *
     * All statuses, not just scheduled: the plan has to know what was already
     * recognized in order to say a full refund's clawback covers it, and a term
     * with nothing scheduled left is exactly the "refund after the term ended"
     * case.
     *
     * A term is at most twelve rows, so this is one query and no pagination —
     * the unbounded thing here is subscriptions, not periods within one.
     *
     * @return list<PeriodLine>
     */
    public function periodLinesFor(int $subscriptionId): array;

    /**
     * Shrinks a period to the days that were actually delivered (F09).
     *
     * Still `scheduled` afterwards, on purpose: the used days have to be
     * *recognized* like any other period, through F05's action and its
     * engagement, rather than being conjured into revenue by a refund. The CAS
     * on `status` stops a concurrent `ledger:accrue` from recognizing the
     * original, longer period underneath us.
     *
     * @return bool true when this call truncated the period
     */
    public function truncatePeriod(int $periodId, CarbonImmutable $newPeriodEnd, int $days, int $grossMinor): bool;

    /**
     * Cancels the named periods, but only those still scheduled (F09).
     *
     * The `status` predicate is the guard against a race with `ledger:accrue`:
     * a period it recognized a moment ago is no longer cancellable, and the
     * count coming back short is how the caller finds out.
     *
     * @param  list<int> $periodIds
     * @return int       periods cancelled by this call
     */
    public function cancelScheduled(array $periodIds): int;

    /**
     * The recognized periods of one term, and the platform's share of them.
     *
     * A full refund reverses both sides of every recognition: the instructors'
     * allocations and the platform's cut. The cut is read from the period row
     * rather than recomputed, for the same reason the allocations are — a
     * second pass through the split could re-round it.
     *
     * @return array{ids: list<int>, platform_minor: int}
     */
    public function recognizedPeriodsFor(int $subscriptionId): array;

    /**
     * Σ gross per subscription, in total and for the cancelled periods alone
     * (verify check 7, invariant I8).
     *
     * Both numbers are needed because a refund changes the schedule in two
     * different ways: cancelling a period leaves its gross in the table, while
     * truncating one lowers it. Only the pair can tell those apart.
     *
     * @param  list<int>                                               $subscriptionIds
     * @return array{all: array<int, int>, cancelled: array<int, int>}
     */
    public function grossTotalsFor(array $subscriptionIds): array;

    /**
     * Σ gross of the periods each of these subscriptions has not yet delivered
     * — everything still `scheduled` (verify check 5).
     *
     * This is what `deferred_revenue[sub]` is *supposed* to equal at every
     * moment, not only once the term is over: the liability is the undelivered
     * time, and recognition moves a period out of this sum and out of that
     * balance in the same transaction. Cancelled periods are excluded because a
     * refund discharges their liability with its own posting.
     *
     * A subscription with nothing left scheduled is absent from the result; the
     * caller reads that as the zero it is.
     *
     * @param  list<int>       $subscriptionIds
     * @return array<int, int> subscription id => undelivered gross in minor units
     */
    public function unrecognizedGrossFor(array $subscriptionIds): array;

    /**
     * The next page of recognized periods with the split they recorded, for the
     * check that `platform + Σ allocations === gross` (invariant I6).
     *
     * @return array<int, array{gross: int, platform: int}> period id => amounts, ascending
     */
    public function recognizedSplits(int $afterId, int $limit = self::DEFAULT_CHUNK_SIZE): array;
}
