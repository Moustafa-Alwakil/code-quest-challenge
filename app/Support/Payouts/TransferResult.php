<?php

declare(strict_types=1);

namespace App\Support\Payouts;

use App\Enums\TransferStatus;
use Carbon\CarbonImmutable;

/**
 * What the provider told us about one transfer (F07).
 *
 * Deliberately has no "was it ok" boolean. Every caller has to `match` on the
 * status and therefore has to say what it does about `PENDING` and
 * `NOT_FOUND` — the two answers a boolean would quietly fold into "no", which
 * is how a timed-out-but-successful transfer gets sent a second time.
 */
final readonly class TransferResult
{
    private function __construct(
        public TransferStatus $status,
        public ?string $providerReference,
        public ?string $failureCode,
        public ?CarbonImmutable $processedAt,
    ) {}

    public static function succeeded(string $providerReference, ?CarbonImmutable $processedAt = null): self
    {
        return new self(TransferStatus::SUCCEEDED, $providerReference, null, $processedAt);
    }

    public static function failed(string $failureCode, ?string $providerReference = null, ?CarbonImmutable $processedAt = null): self
    {
        return new self(TransferStatus::FAILED, $providerReference, $failureCode, $processedAt);
    }

    public static function pending(?string $providerReference = null): self
    {
        return new self(TransferStatus::PENDING, $providerReference, null, null);
    }

    /**
     * The provider has never seen this key — so the transfer provably did not
     * happen, and sending it again with the same key is safe.
     */
    public static function notFound(): self
    {
        return new self(TransferStatus::NOT_FOUND, null, null, null);
    }

    /**
     * A one-line summary for the audit trail.
     */
    public function describe(): string
    {
        return match (true) {
            $this->failureCode !== null => "{$this->status->value} ({$this->failureCode})",
            $this->providerReference !== null => "{$this->status->value} ({$this->providerReference})",
            default => $this->status->value,
        };
    }
}
