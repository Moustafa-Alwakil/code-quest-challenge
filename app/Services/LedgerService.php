<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerAccountType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\LedgerEntry;
use App\Support\Ledger\BalanceDelta;
use App\Support\Ledger\LedgerLeg;
use App\Support\Ledger\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Writes to the ledger — and nothing else does (D-9, F03).
 *
 * `post()` is the whole idempotency mechanism. Its correctness rests on the
 * UNIQUE index `(entry_type, reference_type, reference_id, account_type,
 * account_id)` and on MySQL's affected-row count, not on a lock: if Redis
 * vanished mid-run, a replayed posting would still be a no-op.
 */
final class LedgerService
{
    public function __construct(
        private InstructorBalanceService $balances,
    ) {}

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
    public function post(LedgerTransaction $transaction, BalanceDelta ...$deltas): bool
    {
        if (DB::transactionLevel() === 0) {
            throw LedgerIntegrityException::outsideTransaction(
                $transaction->entryType->value,
                $transaction->referenceType,
                $transaction->referenceId,
            );
        }

        $transactionUuid = (string) Str::uuid();
        $now = now();

        $rows = array_map(static fn (LedgerLeg $leg): array => [
            'transaction_uuid' => $transactionUuid,
            'account_type' => $leg->accountType->value,
            'account_id' => $leg->accountId,
            'amount_minor' => $leg->amount->minor,
            'currency' => $leg->amount->currency,
            'entry_type' => $transaction->entryType->value,
            'reference_type' => $transaction->referenceType,
            'reference_id' => $transaction->referenceId,
            'created_at' => $now,
        ], $transaction->legs);

        $inserted = DB::table('ledger_entries')->insertOrIgnore($rows);

        if ($inserted === 0) {
            return false;
        }

        if ($inserted !== $transaction->legCount()) {
            throw LedgerIntegrityException::partialInsert(
                $transaction->legCount(),
                $inserted,
                $transaction->entryType->value,
                $transaction->referenceType,
                $transaction->referenceId,
            );
        }

        $this->balances->applyDeltas(
            $transaction->currency,
            $this->lastEntryIdOf($transactionUuid),
            ...$deltas,
        );

        return true;
    }

    /**
     * Check 1 — every piastre that entered the ledger also left it.
     *
     * An aggregate, not a row scan: at production scale this runs incrementally
     * from a watermark rather than over the full table (F03 notes).
     */
    public function sumOfAllEntries(): int
    {
        return self::asInt(DB::table('ledger_entries')->sum('amount_minor'));
    }

    /**
     * Check 2 — the transactions whose legs do not sum to zero.
     *
     * The grouping happens in MySQL and the HAVING clause keeps only the
     * failures, so the result set is empty on a healthy ledger however large
     * the table is.
     *
     * @return array<string, int> transaction_uuid => the amount it is out by
     */
    public function unbalancedTransactions(): array
    {
        $offenders = [];

        $rows = DB::table('ledger_entries')
            ->selectRaw('transaction_uuid, sum(amount_minor) as delta_minor')
            ->groupBy('transaction_uuid')
            ->havingRaw('sum(amount_minor) <> 0')
            ->get();

        foreach ($rows as $row) {
            $offenders[self::asString($row->transaction_uuid)] = self::asInt($row->delta_minor);
        }

        return $offenders;
    }

    public function transactionCount(): int
    {
        return self::asInt(DB::table('ledger_entries')->distinct()->count('transaction_uuid'));
    }

    /**
     * How many entries a verification run looked at. Restricted to an
     * instructor, it counts only the legs that instructor's balance is
     * recomputed from.
     */
    public function entryCount(?int $instructorId = null): int
    {
        return DB::table('ledger_entries')
            ->when($instructorId !== null, fn ($query) => $query
                ->whereIn('account_type', array_map(
                    static fn (LedgerAccountType $accountType): string => $accountType->value,
                    LedgerAccountType::instructorKeyed(),
                ))
                ->where('account_id', $instructorId))
            ->count();
    }

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
    public function postMany(array $transactions): int
    {
        if ($transactions === []) {
            return 0;
        }

        if (DB::transactionLevel() === 0) {
            throw LedgerIntegrityException::outsideTransaction(
                $transactions[0]->entryType->value,
                $transactions[0]->referenceType,
                $transactions[0]->referenceId,
            );
        }

        $now = now();
        $rows = [];

        foreach ($transactions as $transaction) {
            $transactionUuid = (string) Str::uuid();

            foreach ($transaction->legs as $leg) {
                $rows[] = [
                    'transaction_uuid' => $transactionUuid,
                    'account_type' => $leg->accountType->value,
                    'account_id' => $leg->accountId,
                    'amount_minor' => $leg->amount->minor,
                    'currency' => $leg->amount->currency,
                    'entry_type' => $transaction->entryType->value,
                    'reference_type' => $transaction->referenceType,
                    'reference_id' => $transaction->referenceId,
                    'created_at' => $now,
                ];
            }
        }

        return DB::table('ledger_entries')->insertOrIgnore($rows);
    }

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
    public function deferredRevenueOwedFor(array $subscriptionIds): array
    {
        if ($subscriptionIds === []) {
            return [];
        }

        $owed = [];

        $rows = DB::table('ledger_entries')
            ->selectRaw('account_id, sum(amount_minor) as sum_minor')
            ->where('account_type', LedgerAccountType::DEFERRED_REVENUE->value)
            ->whereIn('account_id', $subscriptionIds)
            ->groupBy('account_id')
            ->get();

        foreach ($rows as $row) {
            $owed[self::asInt($row->account_id)] = -self::asInt($row->sum_minor);
        }

        return $owed;
    }

    /**
     * A raw aggregate comes back untyped — MySQL hands SUM() over as a string.
     * Narrowing it here, loudly, beats trusting a cast: if the driver ever
     * returns something else, the run fails instead of silently reading 0.
     */
    private static function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Expected a numeric aggregate from the ledger, got '.get_debug_type($value).'.');
        }

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a string column from the ledger, got '.get_debug_type($value).'.');
        }

        return $value;
    }

    /**
     * The watermark stored on the snapshot rows this posting touches.
     *
     * `insertOrIgnore` does not hand back the ids it wrote, and the
     * transaction_uuid index makes reading them back cheap. The legs were
     * inserted a statement ago in this same transaction, so a miss here is
     * impossible — and worth failing on if it ever happens.
     */
    private function lastEntryIdOf(string $transactionUuid): int
    {
        return LedgerEntry::query()
            ->where('transaction_uuid', $transactionUuid)
            ->orderByDesc('id')
            ->firstOrFail(['id'])->id;
    }
}
