<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Money;
use App\Support\Refunds\SubscriptionForRefund;
use App\Support\Subscriptions\BulkTerm;
use Carbon\CarbonImmutable;

/**
 * The subscriptions aggregate, payments included.
 *
 * One aggregate, not two: a payment is 1:1 with the term it bought, and both
 * rows are always written together in one transaction the Action owns.
 */
interface SubscriptionServiceContract
{
    /**
     * The subscription a captured payment already paid for, if this
     * `external_ref` is on file.
     *
     * The replay check, and the reason a replay writes nothing at all.
     */
    public function subscriptionIdForExternalRef(string $externalRef): ?int;

    /**
     * @param Money $price snapshotted from the plan, so a later price change cannot reach it
     */
    public function createActive(int $userId, int $planId, CarbonImmutable $termStart, CarbonImmutable $termEnd, int $termDays, Money $price): int;

    /**
     * @return int the payment's id, which the `payment_received` posting is keyed by
     */
    public function recordPayment(int $subscriptionId, string $externalRef, Money $amount, CarbonImmutable $capturedAt): int;

    /**
     * Writes a chunk of terms, their payments and their schedules as four
     * statements (F02's `ScaleSeeder`).
     *
     * Deliberately not a loop over `createActive()` and `recordPayment()`:
     * fifty thousand terms that way is fifty thousand round trips and a scale
     * seeder nobody will wait for. This is the same data through the same
     * columns, written in bulk.
     *
     * **Ids come from MySQL's consecutive-autoincrement guarantee.** A single
     * multi-row `INSERT` reports the id of its *first* row, and under the
     * default `innodb_autoinc_lock_mode = 1` the rest follow consecutively in
     * row order. That is what lets the payments and periods below reference
     * their term without a read-back — and it is why this uses `insert()`
     * rather than `insertOrIgnore()`, which would break the run of ids the
     * moment one row were skipped.
     *
     * The caller is therefore responsible for not handing it a term that
     * already exists; `ScaleSeeder` asserts an empty table rather than
     * pretending to be idempotent.
     *
     * @param  list<BulkTerm> $terms
     * @return int            the first subscription id written
     */
    public function insertTermsInBulk(array $terms): int;

    /**
     * The term a refund is about, locked for the caller's transaction (F09).
     *
     * The lock is the outermost one a refund takes, and every other lock in the
     * system is taken after it — instructor balances ascending, periods by id.
     * One order everywhere is what keeps a refund and a concurrent payout run
     * from deadlocking on the same instructor.
     */
    public function lockForRefund(int $subscriptionId): ?SubscriptionForRefund;

    /**
     * The same read without the lock, for `--dry-run`.
     *
     * A preview that took row locks would block a concurrent recognition for
     * as long as someone was looking at the output, which is a strange price to
     * pay for a number nobody is acting on yet.
     */
    public function findForRefund(int $subscriptionId): ?SubscriptionForRefund;

    /**
     * Marks a term refunded, from either state a live term can be in.
     *
     * `expired` is admitted as well as `active` because a term whose last
     * period has closed can still be refunded — the pro-rata amount is simply
     * zero, and F09's edge-case table says the status should still move. The
     * CAS excludes `refunded`, which is what makes a second refund a no-op
     * rather than a second cancellation.
     *
     * @return bool true when this call refunded the term
     */
    public function markRefunded(int $subscriptionId, CarbonImmutable $canceledAt): bool;

    /**
     * The price each of these terms was sold at, snapshotted at purchase (F04).
     *
     * @param  list<int>       $subscriptionIds
     * @return array<int, int>
     */
    public function pricesFor(array $subscriptionIds): array;

    /**
     * The next page of subscription ids, for a verification run that has to
     * visit every term.
     *
     * Keyset (`WHERE id > ?`), never OFFSET: the set is large and a run that
     * re-counted from the start on every page would be quadratic.
     *
     * @return list<int> ascending
     */
    public function idsAfter(int $afterId, int $limit): array;

    /**
     * Closes off one bounded batch of finished terms.
     *
     * A conditional `UPDATE ... WHERE status = 'active'`, so it is idempotent by
     * construction: a row it has already expired no longer matches. The caller
     * loops until a batch comes back short, which keeps each transaction — and
     * each set of row locks — bounded however many terms ended overnight.
     *
     * `term_end` is exclusive, so a term ending today is over today.
     *
     * @return int rows expired by this batch
     */
    public function expireTermsEndedBy(CarbonImmutable $asOf, int $limit): int;
}
