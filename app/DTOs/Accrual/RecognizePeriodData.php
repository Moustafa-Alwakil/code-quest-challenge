<?php

declare(strict_types=1);

namespace App\DTOs\Accrual;

use App\Enums\ZeroEngagementPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * One accrual period, and the policy to recognize it under (F05).
 *
 * The dials travel with the request rather than being read where they are used.
 * A chunk job carries them as scalars and rebuilds this object in `handle()`
 * (R15, R27), so a retry recognizes at the rate and the hold its dispatch
 * intended — not at whatever `config/revenue.php` says when the worker happens
 * to pick the job up, which could be after a deploy.
 *
 * F09 builds the same object for a period it has just truncated, which is why
 * `forPeriod()` is not tied to a command run.
 */
final readonly class RecognizePeriodData
{
    private function __construct(
        public int $periodId,
        public int $instructorShareBps,
        public int $holdDays,
        public string $currency,
        public ZeroEngagementPolicy $zeroEngagementPolicy,
        public CarbonImmutable $recognizedAt,
    ) {}

    /**
     * @throws InvalidArgumentException on a non-positive period id or an out-of-range share
     */
    public static function forPeriod(
        int $periodId,
        int $instructorShareBps,
        int $holdDays,
        string $currency,
        ZeroEngagementPolicy $zeroEngagementPolicy,
        CarbonImmutable $recognizedAt,
    ): self {
        if ($periodId <= 0) {
            throw new InvalidArgumentException("A recognition needs a positive period id, got {$periodId}.");
        }

        if ($instructorShareBps < 0 || $instructorShareBps > 10_000) {
            throw new InvalidArgumentException("The instructor share must be 0-10000 bps, got {$instructorShareBps}.");
        }

        if ($holdDays < 0) {
            throw new InvalidArgumentException("The hold cannot be negative, got {$holdDays} days.");
        }

        return new self($periodId, $instructorShareBps, $holdDays, $currency, $zeroEngagementPolicy, $recognizedAt);
    }

    /**
     * Rebuilt inside a job's `handle()` from the scalars it was constructed
     * with — a serialized DTO would go stale across a deploy (R15), and a
     * re-read clock would move the window the retry acts on (R27).
     *
     * @throws InvalidArgumentException on an unparseable instant or an unknown policy
     */
    public static function fromJob(
        int $periodId,
        int $instructorShareBps,
        int $holdDays,
        string $currency,
        string $zeroEngagementPolicy,
        string $recognizedAt,
    ): self {
        $policy = ZeroEngagementPolicy::tryFrom($zeroEngagementPolicy);

        if (! $policy instanceof ZeroEngagementPolicy) {
            throw new InvalidArgumentException("Unknown zero-engagement policy '{$zeroEngagementPolicy}'.");
        }

        try {
            $instant = CarbonImmutable::parse($recognizedAt);
        } catch (Throwable $invalid) {
            throw new InvalidArgumentException("A job carried an unparseable recognition instant '{$recognizedAt}'.", previous: $invalid);
        }

        return self::forPeriod($periodId, $instructorShareBps, $holdDays, $currency, $policy, $instant);
    }
}
