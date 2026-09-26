<?php

declare(strict_types=1);

use App\Exceptions\AccrualScheduleException;
use App\Support\Accrual\AccrualSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;

/*
 * The boundary arithmetic, tested without a database because it has no I/O in
 * it (R10, D-1, D-5).
 *
 * The case this file exists for is the one a chained implementation gets wrong
 * and no ledger test would notice: an annual term starting on Jan 31 must run
 * Feb 28/29 -> Mar 31 -> Apr 30, because every boundary is computed from the
 * anchor. Chaining gives Feb 28 -> Mar 28 -> Apr 28 and drifts for a year,
 * which moves real money between periods and between refunds.
 */

/**
 * The three seeded plans, as (interval_months, price_minor) pairs.
 *
 * Prices that do not divide by their day counts are the point: they force the
 * largest-remainder path on every case below.
 */
function seededPlans(): array
{
    return [
        'monthly' => [1, 30_000],
        'quarterly' => [3, 80_000],
        'annual' => [12, 300_000],
    ];
}

/**
 * Whole days between two YYYY-MM-DD dates, from PHP's own calendar and with no
 * float in it.
 *
 * Deliberately not Carbon's `diffInDays()`: that is the call under test, and a
 * day count checked against itself is not checked at all. This is the oracle
 * that makes `days` provable rather than merely self-consistent — `Σ gross ===
 * price` cannot catch a wrong day count, because largest remainder always sums.
 */
function calendarDaysBetween(string $from, string $to): int
{
    $utc = new DateTimeZone('UTC');

    return (int) (new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc))->days;
}

/**
 * Everything about a schedule that money depends on, as a comparable value.
 */
function scheduleFingerprint(AccrualSchedule $schedule): array
{
    return [
        'termStart' => $schedule->termStart->toDateString(),
        'termEnd' => $schedule->termEnd->toDateString(),
        'termDays' => $schedule->termDays,
        'periods' => array_map(static fn ($period): array => [
            $period->sequence,
            $period->periodStart->toDateString(),
            $period->periodEnd->toDateString(),
            $period->days,
            $period->gross->minor,
        ], $schedule->periods),
    ];
}

/**
 * The local dates on which a zone changes its UTC offset, plus each one's
 * neighbours — the only dates where a timezone can change the answer.
 *
 * Read from PHP's own transition table rather than hard-coded, so the test keeps
 * testing the real thing if a country moves its rules again.
 *
 * @return list<string>
 */
function offsetChangeDates(string $zone): array
{
    $timezone = new DateTimeZone($zone);
    $from = (new DateTimeImmutable('2023-01-01', $timezone))->getTimestamp();
    $to = (new DateTimeImmutable('2026-01-01', $timezone))->getTimestamp();

    $dates = [];

    foreach ($timezone->getTransitions($from, $to) as $transition) {
        $moment = (new DateTimeImmutable('@'.$transition['ts']))->setTimezone($timezone);

        foreach ([-1, 0, 1] as $offsetDays) {
            $dates[] = $moment->modify(sprintf('%+d day', $offsetDays))->format('Y-m-d');
        }
    }

    return array_values(array_unique($dates));
}

function scheduleFrom(string $termStart, int $intervalMonths, int $priceMinor): AccrualSchedule
{
    return AccrualSchedule::forTerm(
        CarbonImmutable::parse($termStart),
        $intervalMonths,
        Money::of($priceMinor, 'EGP'),
    );
}

/**
 * @return list<string> every boundary, starting at the term start
 */
function boundariesOf(AccrualSchedule $schedule): array
{
    $boundaries = [$schedule->termStart->toDateString()];

    foreach ($schedule->periods as $period) {
        $boundaries[] = $period->periodEnd->toDateString();
    }

    return $boundaries;
}

it('anchors every boundary to the term start, so a Jan 31 annual term never drifts', function (): void {
    $schedule = scheduleFrom('2024-01-31', 12, 300_000);

    expect(boundariesOf($schedule))->toBe([
        '2024-01-31',
        '2024-02-29',
        '2024-03-31',
        '2024-04-30',
        '2024-05-31',
        '2024-06-30',
        '2024-07-31',
        '2024-08-31',
        '2024-09-30',
        '2024-10-31',
        '2024-11-30',
        '2024-12-31',
        '2025-01-31',
    ])
        ->and($schedule->termDays)->toBe(366)
        ->and($schedule->grossTotal()->minor)->toBe(300_000);
});

it('recovers the month end after February in a non-leap year too', function (): void {
    $schedule = scheduleFrom('2025-01-31', 3, 80_000);

    expect(boundariesOf($schedule))->toBe(['2025-01-31', '2025-02-28', '2025-03-31', '2025-04-30'])
        ->and(array_map(fn ($period): int => $period->days, $schedule->periods))->toBe([28, 31, 30]);
});

it('gives a monthly plan one period worth the whole price', function (): void {
    $schedule = scheduleFrom('2024-01-01', 1, 30_000);

    expect($schedule->periodCount())->toBe(1)
        ->and($schedule->periods[0]->sequence)->toBe(1)
        ->and($schedule->periods[0]->periodStart->toDateString())->toBe('2024-01-01')
        ->and($schedule->periods[0]->periodEnd->toDateString())->toBe('2024-02-01')
        ->and($schedule->periods[0]->days)->toBe(31)
        ->and($schedule->periods[0]->gross->minor)->toBe(30_000)
        ->and($schedule->termDays)->toBe(31);
});

it('splits a price that does not divide by the day counts, earlier sequences winning ties', function (): void {
    /** 31 / 28 / 31 days of EGP 800.00: bases 27 555 / 24 888 / 27 555, two piastres left over. */
    $schedule = scheduleFrom('2023-01-01', 3, 80_000);

    expect(array_map(fn ($period): int => $period->days, $schedule->periods))->toBe([31, 28, 31])
        ->and(array_map(fn ($period): int => $period->gross->minor, $schedule->periods))
        ->toBe([27_556, 24_889, 27_555])
        ->and($schedule->grossTotal()->minor)->toBe(80_000);
});

it('weights a leap February above a non-leap one for the same annual price', function (): void {
    $leap = scheduleFrom('2024-02-01', 12, 300_000);
    $plain = scheduleFrom('2023-02-01', 12, 300_000);

    expect($leap->periods[0]->days)->toBe(29)
        ->and($plain->periods[0]->days)->toBe(28)
        ->and($leap->periods[0]->gross->minor)->toBeGreaterThan($plain->periods[0]->gross->minor)
        ->and($leap->termDays)->toBe(366)
        ->and($plain->termDays)->toBe(365);
});

it('sums to the price with no drift for every plan on every start date of a leap year', function (string $plan): void {
    [$intervalMonths, $priceMinor] = seededPlans()[$plan];

    $start = CarbonImmutable::parse('2024-01-01');

    for ($day = 0; $day < 366; $day++) {
        $anchor = $start->addDays($day);
        $schedule = AccrualSchedule::forTerm($anchor, $intervalMonths, Money::of($priceMinor, 'EGP'));

        $allocated = 0;
        $days = 0;
        $expectedStart = $anchor;

        foreach ($schedule->periods as $index => $period) {
            $allocated += $period->gross->minor;
            $days += $period->days;

            /** Contiguous and half-open: this period starts exactly where the last one ended. */
            expect($period->sequence)->toBe($index + 1)
                ->and($period->periodStart->toDateString())->toBe($expectedStart->toDateString())
                /** The boundary is the anchor plus k months — never the previous boundary plus one. */
                ->and($period->periodEnd->toDateString())->toBe($anchor->addMonthsNoOverflow($index + 1)->toDateString())
                ->and($period->days)->toBeGreaterThan(0)
                /**
                 * The structural invariant: `days` is the calendar distance
                 * between its own two boundaries, measured without Carbon. This
                 * is the assertion whose absence let a truncated day count
                 * through — the split still summed to the price, and every other
                 * expectation here still held.
                 */
                ->and($period->days)->toBe(
                    calendarDaysBetween($period->periodStart->toDateString(), $period->periodEnd->toDateString()),
                    "Period {$period->sequence} of a {$plan} term starting {$anchor->toDateString()} counts {$period->days} days between {$period->periodStart->toDateString()} and {$period->periodEnd->toDateString()}.",
                );

            $expectedStart = $period->periodEnd;
        }

        expect($allocated)->toBe($priceMinor, "Σ gross drifted for a {$plan} term starting {$anchor->toDateString()}.")
            ->and($days)->toBe($schedule->termDays)
            /** And the term's own span agrees with the periods that make it up. */
            ->and($schedule->termDays)->toBe(
                calendarDaysBetween($schedule->termStart->toDateString(), $schedule->termEnd->toDateString()),
                "term_days disagrees with term_end - term_start for a {$plan} term starting {$anchor->toDateString()}.",
            )
            ->and($schedule->periodCount())->toBe($intervalMonths)
            ->and($schedule->termEnd->toDateString())->toBe($anchor->addMonthsNoOverflow($intervalMonths)->toDateString())
            ->and($expectedStart->toDateString())->toBe($schedule->termEnd->toDateString());
    }
})->with(['monthly', 'quarterly', 'annual']);

it('normalizes a capture time to its date, so a 23:00 purchase still gets whole days', function (): void {
    $schedule = AccrualSchedule::forTerm(
        CarbonImmutable::parse('2024-01-31 23:00:00'),
        12,
        Money::of(300_000, 'EGP'),
    );

    expect($schedule->termStart->toDateTimeString())->toBe('2024-01-31 00:00:00')
        ->and($schedule->termEnd->toDateString())->toBe('2025-01-31')
        ->and($schedule->termDays)->toBe(366);
});

it('is deterministic: the same term produces byte-identical schedules', function (): void {
    $first = scheduleFrom('2024-01-31', 12, 300_000);
    $second = scheduleFrom('2024-01-31', 12, 300_000);

    $flatten = fn (AccrualSchedule $schedule): array => array_map(
        fn ($period): array => [$period->sequence, $period->periodStart->toDateString(), $period->days, $period->gross->minor],
        $schedule->periods,
    );

    expect($flatten($first))->toBe($flatten($second));
});

it('allocates nothing to a zero-priced term without throwing', function (): void {
    $schedule = scheduleFrom('2024-01-31', 12, 0);

    expect($schedule->grossTotal()->minor)->toBe(0)
        ->and($schedule->periodCount())->toBe(12);
});

it('refuses a term length no plan sells', function (int $intervalMonths): void {
    expect(fn () => scheduleFrom('2024-01-01', $intervalMonths, 30_000))
        ->toThrow(AccrualScheduleException::class);
})->with([0, -1, 13]);

it('refuses a negative price rather than writing a schedule that owes money', function (): void {
    expect(fn () => scheduleFrom('2024-01-01', 12, -1))
        ->toThrow(AccrualScheduleException::class, 'cannot be priced below zero');
});

it('schedules a local instant exactly as it schedules that same calendar date in UTC', function (string $zone): void {
    /*
     * The generalization of the day-count defect (R26). `forTerm()` rebuilds its
     * anchor at midnight UTC from the caller's calendar date, so a capture at any
     * time of day in any zone must produce one schedule — the one belonging to
     * that local date.
     *
     * Two ways of getting this wrong are both caught here. `startOfDay()` in the
     * caller's zone returns 01:00 on a date whose midnight a DST shift removes,
     * which truncates a day count; `->utc()` moves a 00:30 Cairo capture to the
     * previous date, which would split `term_start` from the `captured_at` it is
     * defined to equal. Note the direction — for a zone ahead of UTC the date
     * slips on 00:30, not on 23:00, so 00:30 is the time of day that catches it.
     * Only the transition dates are swept: the full 366-date dataset above has
     * already established there is nothing else to find.
     */
    $price = Money::of(300_000, 'EGP');
    $compared = 0;

    foreach (offsetChangeDates($zone) as $date) {
        foreach (['00:30:00', '13:45:00', '23:00:00'] as $time) {
            $local = CarbonImmutable::parse("{$date} {$time}", $zone);
            $sameDateInUtc = CarbonImmutable::parse($local->toDateString(), 'UTC');

            foreach ([1, 3, 12] as $intervalMonths) {
                $fromLocal = AccrualSchedule::forTerm($local, $intervalMonths, $price);

                expect(scheduleFingerprint($fromLocal))
                    ->toBe(
                        scheduleFingerprint(AccrualSchedule::forTerm($sameDateInUtc, $intervalMonths, $price)),
                        "A {$intervalMonths}-month term captured at {$date} {$time} in {$zone} scheduled differently from the same date in UTC.",
                    )
                    /**
                     * The term keeps the caller's date, so `captured_at` and `term_start` stay the
                     * same day. Compared against the constructed instant's own date, not the
                     * requested `$date`: a zone whose clocks move at 22:00 or 23:00 has no such
                     * wall time on its transition date, and PHP resolves it forward to the next
                     * day — which is correct, and which asserting `$date` would flag as a failure.
                     */
                    ->and($fromLocal->termStart->toDateString())->toBe($local->toDateString());

                /** And both sides are right, not merely equal, against the calendar oracle. */
                foreach ($fromLocal->periods as $period) {
                    expect($period->days)->toBe(
                        calendarDaysBetween($period->periodStart->toDateString(), $period->periodEnd->toDateString()),
                    );
                }

                $compared++;
            }
        }
    }

    expect($compared)->toBeGreaterThan(0, "No offset changes were found for {$zone}, so this test proved nothing.");
})->with([
    'Africa/Cairo',
    /** Southern hemisphere, and its clocks move at midnight: 2023-09-03 has no 00:00 there. */
    'America/Santiago',
]);
