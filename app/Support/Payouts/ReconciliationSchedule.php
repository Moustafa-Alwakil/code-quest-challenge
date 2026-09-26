<?php

declare(strict_types=1);

namespace App\Support\Payouts;

use Carbon\CarbonImmutable;

/**
 * When to ask the provider again, and when to stop asking (F08).
 *
 * Pure arithmetic over an instant and a count, so the backoff ladder and both
 * windows can be tested without a database, a clock or a provider.
 *
 * The three numbers are operational, not money policy — they tune how hard we
 * chase an answer, and no amount of tuning changes what a piastre is worth.
 * That is why they live here as constants rather than in `config/revenue.php`
 * alongside the dials that do change outcomes.
 */
final class ReconciliationSchedule
{
    /**
     * 1m → 5m → 30m → 2h → 6h, then 6h forever until the cap.
     *
     * Front-loaded because most uncertainty resolves in seconds: a provider
     * that has accepted a transfer usually knows within a minute. The long tail
     * exists so a provider having a bad afternoon is not hammered.
     *
     * @var list<int>
     */
    private const LADDER_MINUTES = [1, 5, 30, 120, 360];

    /**
     * How long a `not_found` is treated as "not visible yet" rather than "never
     * happened".
     *
     * A provider's status API can lag its transfer API by seconds or minutes,
     * so `not_found` straight after a timeout is the least trustworthy answer
     * it can give. Resending inside this window risks a second transfer against
     * a weaker dedup than ours; waiting it out costs fifteen minutes.
     */
    private const NOT_FOUND_GRACE_MINUTES = 15;

    /**
     * After this long unresolved, a human looks at it. The money stays frozen.
     */
    private const GIVE_UP_HOURS = 24;

    /**
     * The next time to ask, given how many times we have already asked.
     *
     * @param int $checksSoFar status calls already recorded, including the one just made
     */
    public static function nextCheckAt(CarbonImmutable $asOf, int $checksSoFar): CarbonImmutable
    {
        $step = max($checksSoFar - 1, 0);
        $index = min($step, count(self::LADDER_MINUTES) - 1);

        return $asOf->addMinutes(self::LADDER_MINUTES[$index]);
    }

    /**
     * Whether a `not_found` is still young enough to be a lagging status API
     * rather than evidence the transfer never happened.
     *
     * An item with no `submitted_at` has not been sent, so there is nothing to
     * be within the grace of.
     */
    public static function isWithinNotFoundGrace(?CarbonImmutable $submittedAt, CarbonImmutable $asOf): bool
    {
        if ($submittedAt === null) {
            return false;
        }

        return $asOf->lessThan($submittedAt->addMinutes(self::NOT_FOUND_GRACE_MINUTES));
    }

    /**
     * Whether we have been asking long enough to stop.
     *
     * Note what this does *not* do: it never turns an unresolved item into a
     * failure. The passage of time is not evidence, and only the provider can
     * say `failed` (D-8). All this decides is when to involve a person.
     */
    public static function hasExhaustedPatience(?CarbonImmutable $submittedAt, CarbonImmutable $asOf): bool
    {
        if ($submittedAt === null) {
            return false;
        }

        return $asOf->greaterThanOrEqualTo($submittedAt->addHours(self::GIVE_UP_HOURS));
    }

    /**
     * How long a `reserved` item may sit undispatched before we assume its job
     * was lost and send another.
     *
     * Safe to get wrong in the impatient direction: a re-dispatch meets the
     * `reserved → submitted` compare-and-swap, and beyond that the same
     * idempotency key meets the provider's dedup.
     */
    public static function strandedBefore(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->subMinutes(30);
    }
}
