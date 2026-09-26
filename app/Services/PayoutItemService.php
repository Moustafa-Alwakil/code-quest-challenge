<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayoutAttemptOperation;
use App\Enums\PayoutItemStatus;
use App\Models\PayoutItem;
use App\Support\Payouts\PayoutItemSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * One payout item's state and its audit trail (F07, F08).
 *
 * Every status change here is a conditional `UPDATE ... WHERE status = ?` whose
 * affected-row count the caller checks — a compare-and-swap the database
 * enforces (PLAN §8.1). That is what makes each transition idempotent on its
 * own: a job delivered twice finds the row already moved and the second CAS
 * affects nothing.
 *
 * Nothing here opens a transaction. The Action decides which of these writes
 * commit together, which matters enormously: the settlement CAS and its ledger
 * posting are one fact, while the submit CAS must commit *alone* and before the
 * provider is called at all.
 */
final class PayoutItemService
{
    /**
     * How long after submitting before reconciliation should start asking.
     */
    private const SUBMITTED_RECHECK_MINUTES = 10;

    public function find(int $payoutItemId): ?PayoutItemSnapshot
    {
        $item = PayoutItem::query()
            ->join('instructors', 'instructors.id', '=', 'payout_items.instructor_id')
            ->where('payout_items.id', $payoutItemId)
            ->first([
                'payout_items.id',
                'payout_items.payout_run_id',
                'payout_items.instructor_id',
                'payout_items.amount_minor',
                'payout_items.currency',
                'payout_items.status',
                'payout_items.idempotency_key',
                'payout_items.attempts',
                'payout_items.submitted_at',
                'instructors.payout_account_ref',
            ]);

        if ($item === null) {
            return null;
        }

        return new PayoutItemSnapshot(
            $payoutItemId,
            self::asInt($item->getAttribute('payout_run_id')),
            self::asInt($item->getAttribute('instructor_id')),
            self::asInt($item->getAttribute('amount_minor')),
            self::asString($item->getAttribute('currency')),
            $item->status,
            self::asString($item->getAttribute('idempotency_key')),
            self::asString($item->getAttribute('payout_account_ref')),
            self::asInt($item->getAttribute('attempts')),
            $item->submitted_at,
        );
    }

    /**
     * Claims the item for sending: `reserved → submitted`, attempts + 1.
     *
     * The caller commits this **before** touching the provider (PLAN §8.2), so
     * a worker killed mid-call leaves a durable `submitted` row with its
     * idempotency key, and reconciliation can find out what really happened.
     * A row still `reserved` after a crash is one nothing was ever sent for.
     *
     * @return bool true when this call claimed the item
     */
    public function markSubmitted(int $payoutItemId, CarbonImmutable $submittedAt): bool
    {
        $claimed = DB::table('payout_items')
            ->where('id', $payoutItemId)
            ->where('status', PayoutItemStatus::RESERVED->value)
            ->update([
                'status' => PayoutItemStatus::SUBMITTED->value,
                'submitted_at' => $submittedAt,
                'next_check_at' => $submittedAt->addMinutes(self::SUBMITTED_RECHECK_MINUTES),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        return $claimed === 1;
    }

    /**
     * Records a definitive outcome, only from a status that was awaiting one.
     *
     * Accepts `submitted` and `unknown` and nothing else, so a late provider
     * response about an item that has already settled changes nothing — it is
     * written to the audit trail by the caller and dropped here.
     *
     * @return bool true when this call moved the item
     */
    public function settle(
        int $payoutItemId,
        PayoutItemStatus $to,
        CarbonImmutable $settledAt,
        ?string $providerReference = null,
        ?string $lastError = null,
    ): bool {
        $moved = DB::table('payout_items')
            ->where('id', $payoutItemId)
            ->whereIn('status', [PayoutItemStatus::SUBMITTED->value, PayoutItemStatus::UNKNOWN->value])
            ->update([
                'status' => $to->value,
                'settled_at' => $settledAt,
                'next_check_at' => null,
                'provider_reference' => $providerReference,
                'last_error' => $lastError === null ? null : Str::limit($lastError, 250),
            ]);

        return $moved === 1;
    }

    /**
     * The outcome nobody knows (D-8). Money stays reserved, and the item is
     * queued to be asked about again.
     *
     * The caller supplies `next_check_at` rather than this deciding it: F07
     * wants to ask again almost immediately, while F08's ladder backs off as
     * the answers keep not arriving, and the policy behind that belongs in one
     * place (`ReconciliationSchedule`) rather than two.
     */
    public function markUnknown(int $payoutItemId, CarbonImmutable $nextCheckAt, string $reason): bool
    {
        $moved = DB::table('payout_items')
            ->where('id', $payoutItemId)
            ->whereIn('status', [PayoutItemStatus::SUBMITTED->value, PayoutItemStatus::UNKNOWN->value])
            ->update([
                'status' => PayoutItemStatus::UNKNOWN->value,
                'next_check_at' => $nextCheckAt,
                'last_error' => Str::limit($reason, 250),
            ]);

        return $moved === 1;
    }

    /**
     * Sends an item back to `reserved` so it can be dispatched again (F08).
     *
     * Only ever reached when the provider has said `not_found` *after* the
     * grace window — that is, it has had time to see the transfer and reports
     * no record of it. The resend uses the same idempotency key, so even if the
     * status API was lying, the provider's dedup stops a second payment.
     *
     * @return bool true when this call released the item for another attempt
     */
    public function markReservedForResend(int $payoutItemId, string $reason): bool
    {
        $moved = DB::table('payout_items')
            ->where('id', $payoutItemId)
            ->whereIn('status', [PayoutItemStatus::SUBMITTED->value, PayoutItemStatus::UNKNOWN->value])
            ->update([
                'status' => PayoutItemStatus::RESERVED->value,
                'next_check_at' => null,
                'last_error' => Str::limit($reason, 250),
            ]);

        return $moved === 1;
    }

    /**
     * Items whose outcome the provider has not settled, due for another ask.
     *
     * Ordered and bounded, on INDEX `(status, next_check_at)`. The sweep is
     * allowed to be behind — an item it misses this run is picked up on the
     * next one, because the predicate is a property of the row rather than of
     * when the sweep happened to look.
     *
     * @return list<int>
     */
    public function dueForReconciliation(CarbonImmutable $asOf, int $limit): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('payout_items')
            ->whereIn('status', [PayoutItemStatus::SUBMITTED->value, PayoutItemStatus::UNKNOWN->value])
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', $asOf)
            ->orderBy('next_check_at')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => self::asInt($id))
            ->all();

        return $ids;
    }

    /**
     * Items reserved long ago that nobody ever tried to send — the job was
     * lost, the worker died before starting, the batch never dispatched.
     *
     * Re-dispatching is safe rather than merely likely to be: the item is still
     * `reserved`, so the compare-and-swap admits exactly one worker, and the
     * key it carries is the one the provider dedups on.
     *
     * @return list<int>
     */
    public function strandedReserved(CarbonImmutable $before, int $limit): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('payout_items')
            ->where('status', PayoutItemStatus::RESERVED->value)
            ->where('created_at', '<', $before)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => self::asInt($id))
            ->all();

        return $ids;
    }

    /**
     * How many times we have already asked this provider about this item.
     *
     * Read from the audit trail rather than kept in a counter column: the
     * attempts *are* the record, and a second source of the same number is a
     * second thing that can be wrong.
     */
    public function statusCheckCount(int $payoutItemId): int
    {
        return DB::table('payout_attempts')
            ->where('payout_item_id', $payoutItemId)
            ->where('operation', PayoutAttemptOperation::STATUS->value)
            ->count();
    }

    /**
     * Where work goes to be looked at by a human.
     *
     * Reached from any non-terminal status, and never the other way: an item
     * here keeps its money reserved rather than returning it to `available`,
     * because nobody has established that the transfer did not happen.
     */
    public function markNeedsReview(int $payoutItemId, string $reason): bool
    {
        $moved = DB::table('payout_items')
            ->where('id', $payoutItemId)
            ->whereIn('status', [
                PayoutItemStatus::RESERVED->value,
                PayoutItemStatus::SUBMITTED->value,
                PayoutItemStatus::UNKNOWN->value,
            ])
            ->update([
                'status' => PayoutItemStatus::NEEDS_REVIEW->value,
                'next_check_at' => null,
                'last_error' => Str::limit($reason, 250),
            ]);

        return $moved === 1;
    }

    /**
     * Appends one interaction to the audit trail (F07 step 5).
     *
     * Written for every call, including the ones that changed nothing: "the
     * provider answered twice and we acted once" is only visible if both
     * answers were recorded.
     */
    public function recordAttempt(
        int $payoutItemId,
        PayoutAttemptOperation $operation,
        string $request,
        string $response,
        string $outcome,
        int $durationMs,
    ): void {
        DB::table('payout_attempts')->insert([
            'payout_item_id' => $payoutItemId,
            'attempt_no' => $this->nextAttemptNo($payoutItemId),
            'operation' => $operation->value,
            'request' => Str::limit($request, 250),
            'response' => Str::limit($response, 250),
            'outcome' => Str::limit($outcome, 30),
            'duration_ms' => $durationMs,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private static function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Expected a numeric column from payout_items, got '.get_debug_type($value).'.');
        }

        return (int) $value;
    }

    private static function asString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Expected a string column from payout_items, got '.get_debug_type($value).'.');
        }

        return $value;
    }

    /**
     * The first attempt on an item the audit trail has never seen is 1.
     */
    private function nextAttemptNo(int $payoutItemId): int
    {
        $highest = DB::table('payout_attempts')
            ->where('payout_item_id', $payoutItemId)
            ->max('attempt_no');

        return (is_numeric($highest) ? (int) $highest : 0) + 1;
    }
}
