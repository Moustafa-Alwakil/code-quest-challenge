<?php

declare(strict_types=1);

namespace App\DTOs\Payouts;

use App\DTOs\Accrual\ReleaseMaturedEarningsData;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What one payout run was asked to pay, and under what policy (F06).
 *
 * The run key is resolved here, once, and carried. It is the idempotency key
 * of the whole command: `payout:2026-09` twice is a resume of one run, and a
 * different key is a deliberate new run over whatever has since matured.
 * Defaulting it inside the Action instead would make "the same run" depend on
 * when the Action happened to read the clock.
 *
 * Reads the clock once in the named constructor and derives both the key's
 * month and `scheduledFor` from it (R27), so a run cannot be keyed to one month
 * and dated to another.
 */
final readonly class RunPayoutsData
{
    /**
     * Instructors reserved per keyset page. Bounded so one page's row locks
     * stay bounded, and each reservation is its own transaction anyway.
     */
    private const DEFAULT_CHUNK_SIZE = 1000;

    private const MAX_RUN_KEY_LENGTH = 64;

    private function __construct(
        public string $runKey,
        public CarbonImmutable $scheduledFor,
        public CarbonImmutable $startedAt,
        public int $minimumAmountMinor,
        public string $currency,
        public int $chunkSize,
        public bool $dryRun,
        public bool $sync,
    ) {}

    /**
     * @param string|null $runKey    the raw `--run-key=` option; `payout:YYYY-MM` when absent
     * @param string|null $minAmount the raw `--min-amount=` option, in minor units
     *
     * @throws InvalidArgumentException on a blank or over-long key, or a negative minimum
     */
    public static function fromCommand(
        ?string $runKey = null,
        ?string $minAmount = null,
        bool $dryRun = false,
        bool $sync = false,
    ): self {
        $now = CarbonImmutable::now();

        return new self(
            self::resolveRunKey($runKey, $now),
            CarbonImmutable::parse($now->toDateString(), 'UTC'),
            $now,
            self::resolveMinimum($minAmount),
            self::currency(),
            self::DEFAULT_CHUNK_SIZE,
            $dryRun,
            $sync,
        );
    }

    /**
     * The maturation sweep this run opens with, against the same instant it
     * reserves against — availability has to be current at the moment the
     * decision is made, not a moment either side of it.
     */
    public function release(): ReleaseMaturedEarningsData
    {
        return ReleaseMaturedEarningsData::asOf($this->startedAt, $this->currency, $this->chunkSize);
    }

    /**
     * The advisory lock's name. An optimization: losing it skips wasted work,
     * never a payment (PLAN §9 row 10).
     */
    public function lockKey(): string
    {
        return "payouts:run:{$this->runKey}";
    }

    private static function resolveRunKey(?string $runKey, CarbonImmutable $now): string
    {
        if ($runKey === null || mb_trim($runKey) === '') {
            return 'payout:'.$now->format('Y-m');
        }

        $key = mb_trim($runKey);

        if (mb_strlen($key) > self::MAX_RUN_KEY_LENGTH) {
            throw new InvalidArgumentException(
                '--run-key must be at most '.self::MAX_RUN_KEY_LENGTH." characters, got {$key}."
            );
        }

        return $key;
    }

    /**
     * Below this a balance carries forward rather than paying a provider fee to
     * move a trivial amount (D-7). Zero is allowed — it means "pay everything
     * positive" — but a negative minimum is not, since a negative balance is
     * never payable.
     */
    private static function resolveMinimum(?string $minAmount): int
    {
        if ($minAmount === null || mb_trim($minAmount) === '') {
            $configured = config('revenue.minimum_payout_minor');

            if (! is_int($configured) || $configured < 0) {
                throw new InvalidArgumentException('revenue.minimum_payout_minor must be a non-negative integer.');
            }

            return $configured;
        }

        if (ctype_digit($minAmount) === false) {
            throw new InvalidArgumentException("--min-amount must be a non-negative integer of minor units, got '{$minAmount}'.");
        }

        return (int) $minAmount;
    }

    private static function currency(): string
    {
        $currency = config('revenue.currency');

        if (! is_string($currency) || mb_strlen($currency) !== 3) {
            throw new InvalidArgumentException('revenue.currency must be a three-letter code.');
        }

        return mb_strtoupper($currency);
    }
}
