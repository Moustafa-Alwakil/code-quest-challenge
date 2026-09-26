<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Enums\PayoutAttemptOperation;
use App\Enums\PayoutItemStatus;
use App\Support\Payouts\PayoutItemSnapshot;
use Carbon\CarbonImmutable;

/**
 * One payout item's state machine and its audit trail (PLAN §8.1).
 *
 * Every status change here is a compare-and-swap whose affected-row count the
 * caller checks, which is what makes each transition idempotent on its own.
 * Nothing here opens a transaction: the Action decides which of these writes
 * commit together, and that decision is load-bearing — the submit CAS must
 * commit *alone*, before the provider is called at all.
 */
interface PayoutItemServiceContract
{
    /**
     * The statuses an item can hold while the provider still owes an answer.
     *
     * The default source of every automated settlement: a worker or the
     * reconciliation sweep may only resolve something it is still waiting on.
     *
     * @var list<PayoutItemStatus>
     */
    public const AWAITING_OUTCOME = [PayoutItemStatus::SUBMITTED, PayoutItemStatus::UNKNOWN];

    /**
     * The status only a person can move an item out of (F08).
     *
     * Kept separate from `AWAITING_OUTCOME` on purpose: widening the automated
     * CAS to include `needs_review` would let a retried job quietly resolve an
     * item a human was asked to look at, which is the one thing parking it
     * there was supposed to prevent.
     *
     * @var list<PayoutItemStatus>
     */
    public const UNDER_REVIEW = [PayoutItemStatus::NEEDS_REVIEW];

    public function find(int $payoutItemId): ?PayoutItemSnapshot;

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
    public function markSubmitted(int $payoutItemId, CarbonImmutable $submittedAt): bool;

    /**
     * Records a definitive outcome, only from a status that was awaiting one.
     *
     * Accepts `submitted` and `unknown` by default and nothing else, so a late
     * provider response about an item that has already settled changes nothing
     * — it is written to the audit trail by the caller and dropped here.
     *
     * `$from` is widened only by `payouts:resolve`, which moves an item out of
     * `needs_review` on a person's finding. Passing the source explicitly keeps
     * that a deliberate act at one call site rather than a permanent hole in
     * the automated path.
     *
     * @param  list<PayoutItemStatus> $from
     * @return bool                   true when this call moved the item
     */
    public function settle(int $payoutItemId, PayoutItemStatus $to, CarbonImmutable $settledAt, ?string $providerReference = null, ?string $lastError = null, array $from = self::AWAITING_OUTCOME): bool;

    /**
     * The outcome nobody knows (D-8). Money stays reserved, and the item is
     * queued to be asked about again.
     *
     * The caller supplies `next_check_at` rather than this deciding it: F07
     * wants to ask again almost immediately, while F08's ladder backs off as
     * the answers keep not arriving, and the policy behind that belongs in one
     * place (`ReconciliationSchedule`) rather than two.
     */
    public function markUnknown(int $payoutItemId, CarbonImmutable $nextCheckAt, string $reason): bool;

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
    public function markReservedForResend(int $payoutItemId, string $reason): bool;

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
    public function dueForReconciliation(CarbonImmutable $asOf, int $limit): array;

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
    public function strandedReserved(CarbonImmutable $before, int $limit): array;

    /**
     * The next page of items the provider confirmed (verify check 8).
     *
     * @return list<int> ascending
     */
    public function succeededItemIdsAfter(int $afterId, int $limit): array;

    /**
     * How many times we have already asked this provider about this item.
     *
     * Read from the audit trail rather than kept in a counter column: the
     * attempts *are* the record, and a second source of the same number is a
     * second thing that can be wrong.
     */
    public function statusCheckCount(int $payoutItemId): int;

    /**
     * Where work goes to be looked at by a human.
     *
     * Reached from any non-terminal status, and never the other way: an item
     * here keeps its money reserved rather than returning it to `available`,
     * because nobody has established that the transfer did not happen.
     */
    public function markNeedsReview(int $payoutItemId, string $reason): bool;

    /**
     * Appends one interaction to the audit trail (F07 step 5).
     *
     * Written for every call, including the ones that changed nothing: "the
     * provider answered twice and we acted once" is only visible if both
     * answers were recorded.
     */
    public function recordAttempt(int $payoutItemId, PayoutAttemptOperation $operation, string $request, string $response, string $outcome, int $durationMs): void;
}
