<?php

declare(strict_types=1);

namespace App\DTOs\Accrual;

use App\Enums\ZeroEngagementPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * What one recognition run was asked to recognize (F05).
 *
 * The clock is read **once** here and both instants are derived from that one
 * read (R27), so `asOf` and `recognizedAt` cannot disagree — a run that decided
 * which periods are due against one "now" and stamped them with another would
 * be a money decision made against two different windows.
 *
 * A future `--date` is refused. This is the contrast R27 draws with
 * `ExpireSubscriptionsData`, which allows one: that sweep moves a cosmetic
 * status, while this one recognizes revenue, and you cannot recognize time that
 * has not happened. A *past* date is a legitimate backfill.
 *
 * The policy dials are read from `config/revenue.php` here, at the boundary,
 * and carried inward as scalars: `App\Support` may not read config, and a chunk
 * job that re-read them on a retry could recognize at a different rate than the
 * run that dispatched it.
 */
final readonly class AccrueRevenueData
{
    /**
     * Periods per chunk job. Bounded so one job's work — and, in `--sync`, one
     * loop's — stays small enough to retry cheaply.
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    private function __construct(
        public CarbonImmutable $asOf,
        public CarbonImmutable $recognizedAt,
        public int $chunkSize,
        public bool $sync,
        public int $instructorShareBps,
        public int $holdDays,
        public string $currency,
        public ZeroEngagementPolicy $zeroEngagementPolicy,
    ) {}

    /**
     * @param string|null $date  the raw `--date=` option; today when absent
     * @param string|null $chunk the raw `--chunk=` option
     * @param bool        $sync  run recognition inline instead of dispatching jobs
     *
     * @throws InvalidArgumentException on an unparseable or future date, a chunk below 1,
     *                                  or a zero-engagement policy that is not implemented
     */
    public static function fromCommand(?string $date = null, ?string $chunk = null, bool $sync = false): self
    {
        /** One clock read, both instants derived from it (R27). */
        $now = CarbonImmutable::now();

        $asOf = $date === null || mb_trim($date) === ''
            ? self::atMidnightUtc($now)
            : self::parseDate($date);

        if ($asOf->greaterThan(self::atMidnightUtc($now))) {
            throw new InvalidArgumentException(
                "--date cannot be in the future: {$asOf->toDateString()} is after {$now->toDateString()}."
            );
        }

        return new self(
            $asOf,
            $now,
            self::parseChunk($chunk),
            $sync,
            self::shareBps(),
            self::holdDays(),
            self::currency(),
            self::policy(),
        );
    }

    /**
     * The per-period input this run hands to each recognition, carrying the
     * dials rather than letting the Action reach for them again.
     */
    public function forPeriod(int $periodId): RecognizePeriodData
    {
        return RecognizePeriodData::forPeriod(
            $periodId,
            $this->instructorShareBps,
            $this->holdDays,
            $this->currency,
            $this->zeroEngagementPolicy,
            $this->recognizedAt,
        );
    }

    /**
     * The maturation sweep that closes every run, against the same instant.
     */
    public function release(): ReleaseMaturedEarningsData
    {
        return ReleaseMaturedEarningsData::asOf($this->recognizedAt, $this->currency, $this->chunkSize);
    }

    /**
     * A calendar date is rebuilt at midnight UTC rather than converted (R26):
     * `->utc()` would move an early-morning Cairo instant to the previous day.
     */
    private static function atMidnightUtc(CarbonImmutable $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant->toDateString(), 'UTC');
    }

    private static function parseDate(string $date): CarbonImmutable
    {
        $trimmed = mb_trim($date);

        try {
            return self::atMidnightUtc(CarbonImmutable::parse($trimmed));
        } catch (Throwable $invalid) {
            throw new InvalidArgumentException("--date must be a date, got '{$date}'.", previous: $invalid);
        }
    }

    private static function parseChunk(?string $chunk): int
    {
        if ($chunk === null || mb_trim($chunk) === '') {
            return self::DEFAULT_CHUNK_SIZE;
        }

        if (ctype_digit($chunk) === false || (int) $chunk < 1) {
            throw new InvalidArgumentException("--chunk must be a positive integer, got '{$chunk}'.");
        }

        return (int) $chunk;
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

    private static function currency(): string
    {
        $currency = config('revenue.currency');

        if (! is_string($currency) || mb_strlen($currency) !== 3) {
            throw new InvalidArgumentException('revenue.currency must be a three-letter code.');
        }

        return mb_strtoupper($currency);
    }

    /**
     * The D-3 dial, typed. An unimplemented policy is refused here rather than
     * falling through to the implemented one: silently retaining revenue for
     * the platform under a config value that asked for a split would be the
     * worst possible reading of a money setting.
     */
    private static function policy(): ZeroEngagementPolicy
    {
        $configured = config('revenue.zero_engagement_policy');

        if (! is_string($configured)) {
            throw new InvalidArgumentException('revenue.zero_engagement_policy must be a string.');
        }

        $policy = ZeroEngagementPolicy::tryFrom($configured);

        if (! $policy instanceof ZeroEngagementPolicy) {
            throw new InvalidArgumentException("Unknown revenue.zero_engagement_policy '{$configured}'.");
        }

        if (! $policy->isImplemented()) {
            throw new InvalidArgumentException(
                "revenue.zero_engagement_policy '{$configured}' is documented but not built; only 'platform_retains' is implemented."
            );
        }

        return $policy;
    }
}
