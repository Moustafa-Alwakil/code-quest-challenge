<?php

declare(strict_types=1);

namespace App\DTOs\Payouts;

use App\Enums\PayoutItemStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A person's finding about a payout nobody could resolve automatically (F08).
 *
 * The reason is required, not decorative. This is the only path in the system
 * where money moves on a human's word rather than a provider's answer, and the
 * audit trail is the entire reason that is acceptable — "settled manually" with
 * no explanation is indistinguishable from a mistake.
 */
final readonly class ResolvePayoutItemData
{
    private const MAX_REASON_LENGTH = 250;

    private function __construct(
        public int $payoutItemId,
        public PayoutItemStatus $outcome,
        public string $reason,
        public ?string $providerReference,
        public CarbonImmutable $resolvedAt,
    ) {}

    /**
     * @param string      $item              the raw `{item}` argument
     * @param string|null $as                the raw `--as=` option
     * @param string|null $reason            the raw `--reason=` option
     * @param string|null $providerReference the raw `--provider-ref=` option
     *
     * @throws InvalidArgumentException on a bad id, a missing reason, or an outcome
     *                                  that is not one a person can establish
     */
    public static function fromCommand(
        string $item,
        ?string $as,
        ?string $reason,
        ?string $providerReference = null,
    ): self {
        if (ctype_digit($item) === false || (int) $item < 1) {
            throw new InvalidArgumentException("The payout item must be a positive id, got '{$item}'.");
        }

        $outcome = self::outcome($as);
        $trimmedReason = $reason === null ? '' : mb_trim($reason);

        if ($trimmedReason === '') {
            throw new InvalidArgumentException(
                '--reason is required: this is the one path where money moves on a person\'s word, '
                .'and the audit trail is what makes that acceptable.'
            );
        }

        $ref = $providerReference === null ? null : mb_trim($providerReference);

        return new self(
            (int) $item,
            $outcome,
            mb_substr($trimmedReason, 0, self::MAX_REASON_LENGTH),
            $ref === '' ? null : $ref,
            CarbonImmutable::now(),
        );
    }

    /**
     * Only the two outcomes a person can actually establish.
     *
     * `unknown` is not offered: an operator who does not know has nothing to
     * tell the system, and the item is already in the state that says so.
     * `needs_review` is not offered either, for the same reason in reverse.
     */
    private static function outcome(?string $as): PayoutItemStatus
    {
        $value = $as === null ? '' : mb_strtolower(mb_trim($as));

        return match ($value) {
            'succeeded' => PayoutItemStatus::SUCCEEDED,
            'failed' => PayoutItemStatus::FAILED,
            default => throw new InvalidArgumentException(
                "--as must be 'succeeded' or 'failed', got '{$as}'."
            ),
        };
    }
}
