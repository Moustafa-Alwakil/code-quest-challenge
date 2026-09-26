<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransferStatus;
use App\Models\MockProviderTransfer;
use App\Support\Payouts\TransferResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The mock provider's persistence, shared by both mocks (F07, R8).
 *
 * A table rather than an array, because the behaviour under test is precisely
 * the one an in-memory mock cannot produce: a worker times out, *dies*, and a
 * different process later asks what happened. That only works if the provider's
 * memory outlives the process that talked to it.
 *
 * Every dedup decision lives here, in one place, so `RandomMockProvider` and
 * `ScriptedMockProvider` cannot disagree about the contract they are both
 * supposed to be modelling.
 */
final class MockProviderStore
{
    public static function resultFor(MockProviderTransfer $transfer): TransferResult
    {
        return match ($transfer->status) {
            TransferStatus::SUCCEEDED => TransferResult::succeeded(
                $transfer->provider_reference ?? 'tr_unknown',
                $transfer->processed_at,
            ),
            TransferStatus::FAILED => TransferResult::failed(
                $transfer->failure_code ?? 'declined',
                $transfer->provider_reference,
                $transfer->processed_at,
            ),
            TransferStatus::PENDING => TransferResult::pending($transfer->provider_reference),
            TransferStatus::NOT_FOUND => TransferResult::notFound(),
        };
    }

    /**
     * The transfer already recorded for this key, if the provider has seen it.
     *
     * The dedup itself: a caller that finds a row here must return its result
     * rather than moving money again, whatever it was about to do.
     */
    public function find(string $idempotencyKey): ?MockProviderTransfer
    {
        return MockProviderTransfer::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * Counts the call without performing anything — every `transfer()` lands
     * here, including the ones the dedup then answers from memory.
     */
    public function recordCall(string $idempotencyKey): void
    {
        MockProviderTransfer::query()
            ->where('idempotency_key', $idempotencyKey)
            ->increment('transfer_calls');
    }

    /**
     * Performs a transfer for a key never seen before.
     *
     * `transfer_executions` is set to 1 here and nowhere else, which is what
     * makes it the suite's source of truth for "the money moved once": a
     * provider whose dedup had broken would have to write a second row, and
     * UNIQUE `idempotency_key` refuses that.
     */
    public function record(
        string $idempotencyKey,
        string $accountRef,
        int $amountMinor,
        string $currency,
        TransferStatus $status,
        int $confirmAfterChecks = 0,
        ?string $failureCode = null,
    ): MockProviderTransfer {
        return MockProviderTransfer::query()->create([
            'idempotency_key' => $idempotencyKey,
            'account_ref' => $accountRef,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => $status,
            'provider_reference' => $status === TransferStatus::FAILED ? null : 'tr_'.Str::lower(Str::random(20)),
            'failure_code' => $failureCode,
            'confirm_after_checks' => $confirmAfterChecks,
            'transfer_executions' => 1,
            'transfer_calls' => 1,
            'processed_at' => $status === TransferStatus::PENDING ? null : CarbonImmutable::now(),
        ]);
    }

    /**
     * Answers a status query, ageing a `pending` transfer towards success.
     *
     * Video scenario 5: a provider that accepts a transfer and confirms it
     * several checks later. The counter advances on every question asked, so
     * the flip happens on the provider's schedule rather than on ours.
     */
    public function checkStatus(MockProviderTransfer $transfer): TransferResult
    {
        if ($transfer->status !== TransferStatus::PENDING) {
            return self::resultFor($transfer);
        }

        $transfer->increment('status_checks');
        $transfer->refresh();

        if ($transfer->status_checks >= $transfer->confirm_after_checks) {
            $transfer->update([
                'status' => TransferStatus::SUCCEEDED,
                'processed_at' => CarbonImmutable::now(),
            ]);

            $transfer->refresh();
        }

        return self::resultFor($transfer);
    }

    /**
     * How many times the provider actually moved money for this key. One, for
     * any key it has ever transferred; zero for one it has never seen.
     */
    public function transferCount(string $idempotencyKey): int
    {
        return self::asCount(MockProviderTransfer::query()
            ->where('idempotency_key', $idempotencyKey)
            ->value('transfer_executions'));
    }

    /**
     * How many times `transfer()` was *called* for this key. Greater than
     * `transferCount()` is the healthy case: it means a retry happened and the
     * dedup absorbed it.
     */
    public function callCount(string $idempotencyKey): int
    {
        return self::asCount(MockProviderTransfer::query()
            ->where('idempotency_key', $idempotencyKey)
            ->value('transfer_calls'));
    }

    /**
     * A key the provider has never seen has been transferred zero times, which
     * is a real answer rather than a missing one.
     */
    private static function asCount(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
