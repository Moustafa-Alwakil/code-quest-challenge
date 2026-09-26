<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Exceptions\LedgerIntegrityException;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerTransaction;

/**
 * The ledger, and the only way into it (D-9).
 *
 * `post()` is the whole idempotency mechanism: its correctness rests on a
 * UNIQUE index and an affected-row count, never on a lock. An implementation
 * that satisfied this interface by any other means would be a different system
 * wearing the same shape.
 */
interface LedgerServiceContract
{
    /**
     * Posts a transaction, and applies its snapshot deltas only if the legs
     * were actually inserted.
     *
     * Three outcomes, and exactly three:
     *
     * - all N legs inserted — a new posting. Deltas applied, returns `true`.
     * - 0 legs inserted — a replay of a posting already recorded. Nothing
     *   applied, returns `false`. The caller treats this as success: the money
     *   moved once, and that once has already happened.
     * - anything in between — a partial duplicate, which no correct caller can
     *   produce. Throws, so the caller's transaction takes the half-written
     *   legs back out with it.
     *
     * @param  BalanceDelta ...$deltas supplied by the action, because only it knows how a
     *                                 movement splits across held, available and reserved
     * @return bool         true when this call wrote the posting, false when it was a replay
     *
     * @throws LedgerIntegrityException outside a transaction, or on a partial duplicate
     */
    public function post(LedgerTransaction $transaction, BalanceDelta ...$deltas): bool;

    /**
     * Check 1 — every piastre that entered the ledger also left it.
     *
     * An aggregate, not a row scan: at production scale this runs incrementally
     * from a watermark rather than over the full table (F03 notes).
     */
    public function sumOfAllEntries(): int;

    /**
     * Check 2 — the transactions whose legs do not sum to zero.
     *
     * The grouping happens in MySQL and the HAVING clause keeps only the
     * failures, so the result set is empty on a healthy ledger however large
     * the table is.
     *
     * @return array<string, int> transaction_uuid => the amount it is out by
     */
    public function unbalancedTransactions(): array;

    public function transactionCount(): int;

    /**
     * How many entries a verification run looked at. Restricted to an
     * instructor, it counts only the legs that instructor's balance is
     * recomputed from.
     */
    public function entryCount(?int $instructorId = null): int;

    /**
     * Posts many transactions in one statement, for bulk ingestion (F02's
     * `ScaleSeeder`).
     *
     * Same table, same unique key, same `insertOrIgnore` — so replaying a batch
     * is as safe as replaying a single posting. What it deliberately does *not*
     * do is apply balance deltas: it exists for postings that move no
     * instructor's snapshot, which at scale means the `payment_received` entries
     * of fifty thousand terms. A batch that needed deltas would need them merged
     * across transactions and ordered by instructor id, and that is `post()`'s
     * job, one posting at a time.
     *
     * Each transaction still gets its own `transaction_uuid`, because "this
     * posting balances" (invariant I2) is a statement about one business fact
     * and merging them would make it unprovable.
     *
     * @param  list<LedgerTransaction> $transactions
     * @return int                     legs written — short of the total when some were replays
     *
     * @throws LedgerIntegrityException outside a transaction
     */
    public function postMany(array $transactions): int;

    /**
     * How many legs each of these payout items has, per entry type (verify
     * check 8, invariant I7).
     *
     * A settled payout should have exactly two `payout_reserved` legs and two
     * `payout_settled` ones — one transaction each. The unique key already
     * makes a *duplicate* impossible, so what this actually catches is the
     * absence: an item marked succeeded that nothing ever reserved, or one
     * whose settlement posting never landed.
     *
     * @param  list<int>                      $payoutItemIds
     * @return array<int, array<string, int>> item id => entry type => leg count
     */
    public function payoutLegCounts(array $payoutItemIds): array;

    /**
     * What each of the given subscriptions is still owed in undelivered time —
     * the balance of its `deferred_revenue` account (R5), for verify check 5.
     *
     * Liabilities are credit-normal, so "owed" is the negated sum. A
     * subscription with no entries is absent from the result rather than
     * present at zero: the caller knows which ids it asked about, and
     * conflating "nothing posted" with "settled to zero" is the distinction the
     * check exists to make.
     *
     * @param  list<int>       $subscriptionIds
     * @return array<int, int> subscription id => owed minor units
     */
    public function deferredRevenueOwedFor(array $subscriptionIds): array;
}
