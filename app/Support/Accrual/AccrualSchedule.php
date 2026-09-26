<?php

declare(strict_types=1);

namespace App\Support\Accrual;

use App\Exceptions\AccrualScheduleException;
use App\Support\Allocator;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The exact accrual schedule of one term, computed and proved before anything
 * is written (D-1, R10).
 *
 * Deterministic and I/O-free like the rest of App\Support: dates in, periods
 * out, no clock and no database. That is what lets the boundary arithmetic be
 * property-tested over every start date of a leap year.
 *
 * **Boundaries come from the anchor, never from each other.**
 * `b_k = termStart + k months`, with no-overflow month addition. Chaining
 * `b_k = b_(k-1) + 1 month` turns a Jan 31 annual term into
 * Jan 31 -> Feb 28 -> Mar 28 -> Apr 28 and drifts for the rest of the year;
 * anchoring gives Jan 31 -> Feb 28 (29 in a leap year) -> Mar 31 -> Apr 30.
 *
 * The price is split across the periods' day counts with the largest-remainder
 * method (D-5), so Σ gross is the price to the piastre — asserted here, because
 * a schedule that does not sum is a money bug and must never reach the table.
 */
final readonly class AccrualSchedule
{
    /**
     * Terms longer than a year are not a plan this system sells, and `sequence`
     * is a TINYINT with 1...12 in mind.
     */
    private const MAX_INTERVAL_MONTHS = 12;

    /**
     * @param list<SchedulePeriod> $periods in ascending sequence
     */
    private function __construct(
        public CarbonImmutable $termStart,
        public CarbonImmutable $termEnd,
        public int $termDays,
        public string $currency,
        public array $periods,
    ) {}

    /**
     * @param CarbonImmutable $termStart any instant; only its calendar date *in its own timezone* is
     *                                   used. The anchor is rebuilt at midnight UTC from that date
     *                                   (R26), so a Cairo 23:00 capture anchors on the Cairo date and
     *                                   `term_start` still equals the `captured_at` it is defined by
     *
     * @throws AccrualScheduleException on an out-of-range interval, a negative price, an empty
     *                                  period, or a split that does not sum to the price
     */
    public static function forTerm(CarbonImmutable $termStart, int $intervalMonths, Money $price): self
    {
        if ($intervalMonths < 1 || $intervalMonths > self::MAX_INTERVAL_MONTHS) {
            throw AccrualScheduleException::invalidIntervalMonths($intervalMonths, self::MAX_INTERVAL_MONTHS);
        }

        if ($price->isNegative()) {
            throw AccrualScheduleException::negativePrice($price->minor, $price->currency);
        }

        /**
         * The anchor is a true midnight UTC instant, rebuilt from the caller's
         * calendar date rather than converted from their instant (R26).
         *
         * `startOfDay()` in the caller's zone is not equivalent: on the night a
         * DST shift removes local midnight — Africa/Cairo, 2023-04-28 — it
         * returns 01:00, and the day count below then truncates a 30-day period
         * to 29. `->utc()` is not equivalent either: it would move an
         * early-morning Cairo capture to the previous date — and a late-evening
         * Honolulu one to the next — splitting `term_start` from the
         * `captured_at` it is defined to equal. Note the direction: for a zone
         * ahead of UTC the date slips on 00:30, not on 23:00, so a 23:00 case
         * is exactly the one that would fail to catch the mistake.
         */
        $anchor = CarbonImmutable::parse($termStart->toDateString(), 'UTC');
        $spans = [];

        for ($sequence = 1; $sequence <= $intervalMonths; $sequence++) {
            $start = $anchor->addMonthsNoOverflow($sequence - 1);
            $end = $anchor->addMonthsNoOverflow($sequence);

            /**
             * Carbon 3 hands back a float, and the cast is exact by
             * construction: the anchor was normalized to midnight UTC a few
             * lines above, UTC has no DST, and so every `addMonthsNoOverflow`
             * result is midnight UTC too. The difference is therefore a whole
             * number of days before it is ever cast.
             *
             * Nothing here rounds — `round`, `floor` and `ceil` are banned in
             * App\Support — and no second mechanism guards it, because any
             * guard would rest on the same precondition this normalization
             * already establishes.
             */
            $days = (int) $start->diffInDays($end);

            if ($days <= 0) {
                throw AccrualScheduleException::emptyPeriod($sequence, $start->toDateString(), $end->toDateString());
            }

            $spans[$sequence] = ['start' => $start, 'end' => $end, 'days' => $days];
        }

        $gross = Allocator::largestRemainder(
            $price->minor,
            array_map(static fn (array $span): int => $span['days'], $spans),
        );

        $periods = [];
        $allocated = 0;
        $termDays = 0;

        foreach ($spans as $sequence => $span) {
            $minor = $gross[$sequence];
            $allocated += $minor;
            $termDays += $span['days'];

            $periods[] = new SchedulePeriod(
                $sequence,
                $span['start'],
                $span['end'],
                $span['days'],
                Money::of($minor, $price->currency),
            );
        }

        if ($allocated !== $price->minor) {
            throw AccrualScheduleException::grossDoesNotSumToPrice($allocated, $price->minor, $price->currency);
        }

        return new self(
            $anchor,
            $anchor->addMonthsNoOverflow($intervalMonths),
            $termDays,
            $price->currency,
            $periods,
        );
    }

    /**
     * Σ of the periods' gross — the price, by construction and by assertion.
     */
    public function grossTotal(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->periods as $period) {
            $total = $total->plus($period->gross);
        }

        return $total;
    }

    public function periodCount(): int
    {
        return count($this->periods);
    }
}
