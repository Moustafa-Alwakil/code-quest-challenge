<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransferStatus;
use App\Exceptions\ProviderTimeoutException;
use App\Support\Payouts\TransferResult;
use InvalidArgumentException;

/**
 * A provider that behaves like a real one on a bad day (F07).
 *
 * Used for the demo and for manual runs, where the point is that nobody —
 * including the person running it — knows which outcome is coming. The four
 * behaviours and their weights are config, so the video can be re-run until it
 * shows the interesting one without editing code.
 *
 * Its dedup is the part that matters: a key it has already transferred gets the
 * original answer back and moves no money. That models what a real provider
 * guarantees, and it is what makes this system's retries safe rather than
 * merely likely to be safe.
 */
final class RandomMockProvider implements PaymentProvider
{
    /**
     * @param array{success: int, permanent_failure: int, timeout_after_success: int, delayed_confirmation: int} $outcomeWeights
     */
    public function __construct(
        private MockProviderStore $store,
        private array $outcomeWeights,
        private int $confirmAfterChecks,
    ) {}

    public function transfer(
        string $idempotencyKey,
        string $accountRef,
        int $amountMinor,
        string $currency,
    ): TransferResult {
        $existing = $this->store->find($idempotencyKey);

        if ($existing !== null) {
            $this->store->recordCall($idempotencyKey);

            return MockProviderStore::resultFor($existing->refresh());
        }

        $outcome = $this->roll();

        $transfer = match ($outcome) {
            'permanent_failure' => $this->store->record(
                $idempotencyKey, $accountRef, $amountMinor, $currency, TransferStatus::FAILED,
                failureCode: 'account_closed',
            ),
            'delayed_confirmation' => $this->store->record(
                $idempotencyKey, $accountRef, $amountMinor, $currency, TransferStatus::PENDING,
                confirmAfterChecks: $this->confirmAfterChecks,
            ),
            default => $this->store->record(
                $idempotencyKey, $accountRef, $amountMinor, $currency, TransferStatus::SUCCEEDED,
            ),
        };

        /**
         * Recorded, then lost. The transfer is real and the caller cannot know
         * it — exactly the shape of a production timeout, and the reason
         * `unknown` exists as a state.
         */
        if ($outcome === 'timeout_after_success') {
            throw ProviderTimeoutException::forKey($idempotencyKey);
        }

        return MockProviderStore::resultFor($transfer);
    }

    public function getStatus(string $idempotencyKey): TransferResult
    {
        $transfer = $this->store->find($idempotencyKey);

        if ($transfer === null) {
            return TransferResult::notFound();
        }

        return $this->store->checkStatus($transfer);
    }

    /**
     * Weighted by integers out of 100, so the distribution is exact and a
     * seeded run reproduces. No floats: a probability that does not sum to
     * exactly 100 is a config bug, and this says so rather than rounding.
     */
    private function roll(): string
    {
        $total = array_sum($this->outcomeWeights);

        if ($total !== 100) {
            throw new InvalidArgumentException("revenue.provider_outcomes must sum to 100, got {$total}.");
        }

        $roll = random_int(1, 100);
        $cursor = 0;

        foreach ($this->outcomeWeights as $outcome => $weight) {
            $cursor += $weight;

            if ($roll <= $cursor) {
                return $outcome;
            }
        }

        return 'success';
    }
}
