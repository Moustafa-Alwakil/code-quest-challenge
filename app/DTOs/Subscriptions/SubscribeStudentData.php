<?php

declare(strict_types=1);

namespace App\DTOs\Subscriptions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A captured payment, as the one thing the subscribe use case needs (F04, R25).
 *
 * Built at the boundary — a seeder, a test, or one day an import or a gateway
 * webhook — so no request shape, array or model ever reaches the Action. Scalars
 * only, and no Illuminate: a DTO is a contract, not a place to reach for the
 * framework.
 *
 * `capturedAt` is the gateway's timestamp, never "now": the term starts when the
 * money was taken, so a record that arrives a week late does not shorten it.
 */
final readonly class SubscribeStudentData
{
    /**
     * The width of `payments.external_ref`. Checked here so an over-long
     * reference fails with its own name rather than as a MySQL error.
     */
    private const MAX_EXTERNAL_REF_LENGTH = 64;

    private function __construct(
        public int $userId,
        public int $planId,
        public string $externalRef,
        public int $amountMinor,
        public string $currency,
        public CarbonImmutable $capturedAt,
    ) {}

    /**
     * @param string $externalRef the gateway's charge id — the idempotency key
     *
     * @throws InvalidArgumentException when the fact is not one that could have been captured
     */
    public static function forCapturedPayment(
        int $userId,
        int $planId,
        string $externalRef,
        int $amountMinor,
        string $currency,
        CarbonImmutable $capturedAt,
    ): self {
        // Native mb_* functions: a DTO carries no Illuminate imports, so Str is not available here.
        $reference = mb_trim($externalRef);

        if ($userId < 1) {
            throw new InvalidArgumentException("A payment needs a positive user id, got {$userId}.");
        }

        if ($planId < 1) {
            throw new InvalidArgumentException("A payment needs a positive plan id, got {$planId}.");
        }

        if ($reference === '') {
            throw new InvalidArgumentException('A captured payment needs a non-empty external_ref; it is the idempotency key.');
        }

        if (mb_strlen($reference) > self::MAX_EXTERNAL_REF_LENGTH) {
            throw new InvalidArgumentException('An external_ref is at most '.self::MAX_EXTERNAL_REF_LENGTH." characters, got {$reference}.");
        }

        if ($amountMinor < 1) {
            throw new InvalidArgumentException("A captured payment moves money, so its amount must be positive, got {$amountMinor}.");
        }

        return new self($userId, $planId, $reference, $amountMinor, mb_strtoupper(mb_trim($currency)), $capturedAt);
    }
}
