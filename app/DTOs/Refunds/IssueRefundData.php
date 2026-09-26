<?php

declare(strict_types=1);

namespace App\DTOs\Refunds;

use App\Enums\RefundType;
use App\Enums\ZeroEngagementPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * A refund the gateway has executed, as this system was told about it (F09).
 *
 * The effective date is a calendar date normalized to midnight UTC (R26), not
 * an instant: it decides which period a refund lands inside, and a period
 * boundary is a date. Resolved once here, from one clock read, and carried
 * (R27) — a refund that decided "today" twice could truncate one period and
 * cancel from another.
 */
final readonly class IssueRefundData
{
    private const MAX_EXTERNAL_REF_LENGTH = 64;

    private const MAX_REASON_LENGTH = 255;

    private function __construct(
        public int $subscriptionId,
        public string $externalRef,
        public RefundType $type,
        public CarbonImmutable $effectiveAt,
        public CarbonImmutable $recordedAt,
        public bool $dryRun,
        public ?string $reason,
        public int $instructorShareBps,
        public int $holdDays,
        public ZeroEngagementPolicy $zeroEngagementPolicy,
    ) {}

    /**
     * @param string      $subscription the raw `{subscription}` argument
     * @param string      $externalRef  the gateway's refund id
     * @param string|null $effective    the raw `--effective=` option; today when absent
     *
     * @throws InvalidArgumentException on a bad id, a blank or over-long ref, or an unparseable date
     */
    public static function fromCommand(
        string $subscription,
        string $externalRef,
        bool $full = false,
        ?string $effective = null,
        bool $dryRun = false,
        ?string $reason = null,
    ): self {
        $now = CarbonImmutable::now();

        if (ctype_digit($subscription) === false || (int) $subscription < 1) {
            throw new InvalidArgumentException("The subscription must be a positive id, got '{$subscription}'.");
        }

        $ref = mb_trim($externalRef);

        if ($ref === '') {
            throw new InvalidArgumentException('--external-ref is required: a refund is keyed by the gateway\'s own id.');
        }

        if (mb_strlen($ref) > self::MAX_EXTERNAL_REF_LENGTH) {
            throw new InvalidArgumentException(
                '--external-ref must be at most '.self::MAX_EXTERNAL_REF_LENGTH." characters, got {$ref}."
            );
        }

        $effectiveAt = $effective === null || mb_trim($effective) === ''
            ? self::atMidnightUtc($now)
            : self::parseDate($effective);

        $trimmedReason = $reason === null ? null : mb_trim($reason);

        return new self(
            (int) $subscription,
            $ref,
            $full ? RefundType::FULL : RefundType::PRORATA,
            $effectiveAt,
            $now,
            $dryRun,
            $trimmedReason === '' ? null : self::truncateReason($trimmedReason),
            self::shareBps(),
            self::holdDays(),
            self::policy(),
        );
    }

    /**
     * A calendar date rebuilt at midnight UTC rather than converted (R26).
     */
    private static function atMidnightUtc(CarbonImmutable $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant->toDateString(), 'UTC');
    }

    private static function parseDate(string $effective): CarbonImmutable
    {
        try {
            return self::atMidnightUtc(CarbonImmutable::parse(mb_trim($effective)));
        } catch (Throwable $invalid) {
            throw new InvalidArgumentException("--effective must be a date, got '{$effective}'.", previous: $invalid);
        }
    }

    private static function truncateReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return mb_strlen($reason) > self::MAX_REASON_LENGTH
            ? mb_substr($reason, 0, self::MAX_REASON_LENGTH)
            : $reason;
    }

    private static function shareBps(): int
    {
        $shareBps = config('revenue.instructor_share_bps');

        if (! is_int($shareBps) || $shareBps < 0 || $shareBps > 10_000) {
            throw new InvalidArgumentException('revenue.instructor_share_bps must be an integer between 0 and 10000.');
        }

        return $shareBps;
    }

    private static function holdDays(): int
    {
        $holdDays = config('revenue.hold_days');

        if (! is_int($holdDays) || $holdDays < 0) {
            throw new InvalidArgumentException('revenue.hold_days must be a non-negative integer.');
        }

        return $holdDays;
    }

    private static function policy(): ZeroEngagementPolicy
    {
        $configured = config('revenue.zero_engagement_policy');
        $policy = is_string($configured) ? ZeroEngagementPolicy::tryFrom($configured) : null;

        if (! $policy instanceof ZeroEngagementPolicy || ! $policy->isImplemented()) {
            throw new InvalidArgumentException('revenue.zero_engagement_policy must name an implemented policy.');
        }

        return $policy;
    }
}
