<?php

declare(strict_types=1);

use App\Support\Accrual\AccrualSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;

/*
 * Independent verification of R10 (F04 review).
 *
 * `AccrualScheduleTest`'s dataset asserts each boundary against
 * `$anchor->addMonthsNoOverflow($k)` — the same expression the implementation
 * evaluates, so it proves the schedule is *anchored* but cannot prove the anchor
 * rule itself is right, and it never independently proves `days`. This file
 * supplies an oracle Carbon has no part in: clamp-the-day-of-month arithmetic
 * over native DateTimeImmutable in UTC, and day counts from DateInterval::$days.
 *
 * A wrong `days` is a money bug even when Σ gross still equals the price: `days`
 * is the weight the price is split by, so one day in the wrong period moves
 * piastres between periods, and Σ days is the subscription's `term_days`.
 */

/**
 * `termStart + k months` with no-overflow clamping, computed without Carbon.
 */
function oracleBoundary(string $anchor, int $months): string
{
    [$year, $month, $day] = array_map('intval', explode('-', $anchor));

    $target = $month - 1 + $months;
    $targetYear = $year + intdiv($target, 12);
    $targetMonth = $target % 12 + 1;

    $lastDayOfTargetMonth = (int) (new DateTimeImmutable(
        sprintf('%04d-%02d-01', $targetYear, $targetMonth),
        new DateTimeZone('UTC'),
    ))->format('t');

    return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, min($day, $lastDayOfTargetMonth));
}

/**
 * Whole days between two YYYY-MM-DD dates, with no float anywhere.
 */
function oracleDays(string $from, string $to): int
{
    $utc = new DateTimeZone('UTC');
    $difference = (new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc));

    return (int) $difference->days;
}

/**
 * @return list<string> the boundaries a chained implementation would produce
 */
function chainedBoundaries(string $anchor, int $months): array
{
    $cursor = CarbonImmutable::parse($anchor)->startOfDay();
    $boundaries = [$cursor->toDateString()];

    for ($month = 0; $month < $months; $month++) {
        $cursor = $cursor->addMonthNoOverflow();
        $boundaries[] = $cursor->toDateString();
    }

    return $boundaries;
}

it('matches an oracle computed without Carbon for every plan on every start date of a leap year and the year after', function (array $plan): void {
    [$intervalMonths, $priceMinor] = $plan;

    $first = CarbonImmutable::parse('2024-01-01');

    for ($day = 0; $day < 731; $day++) {
        $anchorDate = $first->addDays($day)->toDateString();

        $schedule = AccrualSchedule::forTerm(
            CarbonImmutable::parse($anchorDate),
            $intervalMonths,
            Money::of($priceMinor, 'EGP'),
        );

        $expectedStarts = [];
        $expectedEnds = [];
        $expectedDays = [];

        for ($sequence = 1; $sequence <= $intervalMonths; $sequence++) {
            $start = oracleBoundary($anchorDate, $sequence - 1);
            $end = oracleBoundary($anchorDate, $sequence);

            $expectedStarts[] = $start;
            $expectedEnds[] = $end;
            $expectedDays[] = oracleDays($start, $end);
        }

        $context = "term starting {$anchorDate} over {$intervalMonths} month(s)";

        expect(array_map(fn ($period): string => $period->periodStart->toDateString(), $schedule->periods))
            ->toBe($expectedStarts, "Period starts drifted for a {$context}.")
            ->and(array_map(fn ($period): string => $period->periodEnd->toDateString(), $schedule->periods))
            ->toBe($expectedEnds, "Period ends drifted for a {$context}.")
            /** The weight the price is split by — proved against a real calendar, not against Carbon. */
            ->and(array_map(fn ($period): int => $period->days, $schedule->periods))
            ->toBe($expectedDays, "Day counts are wrong for a {$context}.")
            ->and($schedule->termDays)->toBe(array_sum($expectedDays))
            ->and($schedule->termDays)->toBe(oracleDays($anchorDate, oracleBoundary($anchorDate, $intervalMonths)))
            ->and($schedule->termEnd->toDateString())->toBe(oracleBoundary($anchorDate, $intervalMonths))
            ->and($schedule->grossTotal()->minor)->toBe($priceMinor, "Σ gross drifted for a {$context}.");
    }
})->with([
    'monthly' => [[1, 30_000]],
    'quarterly' => [[3, 80_000]],
    'annual' => [[12, 300_000]],
]);

it('anchors a Feb 29 start and lands on Feb 28 a year later', function (): void {
    $schedule = AccrualSchedule::forTerm(CarbonImmutable::parse('2024-02-29'), 12, Money::of(300_000, 'EGP'));

    expect(array_map(fn ($period): string => $period->periodEnd->toDateString(), $schedule->periods))->toBe([
        '2024-03-29', '2024-04-29', '2024-05-29', '2024-06-29', '2024-07-29', '2024-08-29',
        '2024-09-29', '2024-10-29', '2024-11-29', '2024-12-29', '2025-01-29', '2025-02-28',
    ])
        ->and($schedule->termDays)->toBe(365)
        ->and($schedule->grossTotal()->minor)->toBe(300_000);
});

it('carries a Dec 31 start across the year end without losing a month end', function (): void {
    $schedule = AccrualSchedule::forTerm(CarbonImmutable::parse('2023-12-31'), 12, Money::of(300_000, 'EGP'));

    expect(array_map(fn ($period): string => $period->periodEnd->toDateString(), $schedule->periods))->toBe([
        '2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30', '2024-05-31', '2024-06-30',
        '2024-07-31', '2024-08-31', '2024-09-30', '2024-10-31', '2024-11-30', '2024-12-31',
    ])
        ->and(array_map(fn ($period): int => $period->days, $schedule->periods))
        ->toBe([31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31])
        ->and($schedule->termDays)->toBe(366)
        ->and($schedule->grossTotal()->minor)->toBe(300_000);
});

it('recovers a 31-day month after a 30-day one from an Aug 31 anchor', function (): void {
    $schedule = AccrualSchedule::forTerm(CarbonImmutable::parse('2023-08-31'), 3, Money::of(80_000, 'EGP'));

    /** Chaining gives Oct 30 here, which would move a day's worth of revenue. */
    expect(array_map(fn ($period): string => $period->periodEnd->toDateString(), $schedule->periods))
        ->toBe(['2023-09-30', '2023-10-31', '2023-11-30'])
        ->and(array_map(fn ($period): int => $period->days, $schedule->periods))->toBe([30, 31, 30]);
});

it('keeps a 30-day-month anchor on the 30th, so Apr 30 reaches Jun 30 and not Jun 29', function (): void {
    $schedule = AccrualSchedule::forTerm(CarbonImmutable::parse('2024-04-30'), 3, Money::of(80_000, 'EGP'));

    expect(array_map(fn ($period): string => $period->periodEnd->toDateString(), $schedule->periods))
        ->toBe(['2024-05-30', '2024-06-30', '2024-07-30'])
        ->and(array_map(fn ($period): int => $period->days, $schedule->periods))->toBe([30, 31, 30])
        /** 30 / 31 / 30 days of EGP 800.00: the two leftover piastres go to the highest
         * remainder (sequence 2) and then to the earlier half of a 57/57 tie (sequence 1). */
        ->and(array_map(fn ($period): int => $period->gross->minor, $schedule->periods))
        ->toBe([26_374, 27_253, 26_373])
        ->and($schedule->grossTotal()->minor)->toBe(80_000);
});

it('diverges from a chained schedule only from the fourth period on, and still gets it right', function (): void {
    $schedule = AccrualSchedule::forTerm(CarbonImmutable::parse('2023-11-30'), 12, Money::of(300_000, 'EGP'));

    $actual = array_merge(
        [$schedule->termStart->toDateString()],
        array_map(fn ($period): string => $period->periodEnd->toDateString(), $schedule->periods),
    );

    $chained = chainedBoundaries('2023-11-30', 12);

    /** The first divergence is boundary 4 — a case the Jan 31 vector cannot catch. */
    $firstDivergence = null;

    foreach ($actual as $index => $boundary) {
        if ($boundary !== $chained[$index]) {
            $firstDivergence = $index;

            break;
        }
    }

    expect($firstDivergence)->toBe(4)
        ->and($actual)->toBe([
            '2023-11-30', '2023-12-30', '2024-01-30', '2024-02-29', '2024-03-30', '2024-04-30',
            '2024-05-30', '2024-06-30', '2024-07-30', '2024-08-30', '2024-09-30', '2024-10-30',
            '2024-11-30',
        ])
        ->and($schedule->termDays)->toBe(366)
        ->and($schedule->grossTotal()->minor)->toBe(300_000);
});

it('counts whole days when a boundary lands on a date whose midnight a DST shift removes', function (): void {
    /*
     * The regression R26 fixed. Africa/Cairo skips 00:00 on the night DST starts
     * (2023-04-28), so `startOfDay()` in the caller's zone returns 01:00 there;
     * `diffInDays()` then sees 29.958… days and the `(int)` cast truncates a
     * 30-day period to 29. Rebuilding the anchor at midnight UTC from the
     * caller's calendar date removes the fractional operand pair entirely.
     *
     * The consequences this guards against are a wrong split weight, a `days`
     * column that disagrees with its own `period_end - period_start`, and a
     * `term_days` short by a day. Σ gross still equals the price — largest
     * remainder always sums — so this assertion is the only thing that notices.
     */
    $schedule = AccrualSchedule::forTerm(
        CarbonImmutable::parse('2023-02-28 13:45:00', 'Africa/Cairo'),
        3,
        Money::of(80_000, 'EGP'),
    );

    expect(array_map(fn ($period): string => $period->periodStart->toDateString(), $schedule->periods))
        ->toBe(['2023-02-28', '2023-03-28', '2023-04-28'])
        ->and(array_map(fn ($period): int => $period->days, $schedule->periods))
        ->toBe([28, 31, 30], 'A period\'s day count must be the calendar distance between its boundaries.')
        ->and($schedule->termDays)->toBe(89);
});

it('anchors on the caller\'s own calendar date, never on the UTC instant behind it', function (string $time, string $zone, string $expectedDate): void {
    /*
     * The other half of R26, and the failure mode a `->utc()` normalization
     * would have introduced: converting the instant moves a 00:30 Cairo capture
     * to the previous UTC date, which would split `term_start` from the
     * `captured_at` it is defined to equal and shift every boundary with it.
     *
     * Kept next to the DST case above because the two are one decision: the
     * caller's date is authoritative, and the arithmetic happens in UTC.
     */
    $schedule = AccrualSchedule::forTerm(
        CarbonImmutable::parse("2024-01-31 {$time}", $zone),
        12,
        Money::of(300_000, 'EGP'),
    );

    expect($schedule->termStart->toDateString())->toBe($expectedDate)
        ->and($schedule->termEnd->toDateString())->toBe('2025-01-31')
        ->and($schedule->termDays)->toBe(366)
        ->and($schedule->periods[0]->periodStart->toDateString())->toBe($expectedDate)
        ->and($schedule->grossTotal()->minor)->toBe(300_000);
})->with([
    /** Behind UTC in local terms: 00:30 Cairo is 22:30 the previous day in UTC. */
    ['00:30:00', 'Africa/Cairo', '2024-01-31'],
    ['23:00:00', 'Africa/Cairo', '2024-01-31'],
    /** And the other way: 23:00 in Honolulu is already the next day in UTC. */
    ['23:00:00', 'Pacific/Honolulu', '2024-01-31'],
    ['00:30:00', 'Pacific/Honolulu', '2024-01-31'],
]);

it('produces the same schedule whatever the clock says and whatever the process timezone is', function (): void {
    /*
     * `CarbonImmutable::parse($date, 'UTC')` on a complete `Y-m-d` string is the
     * one piece of R26 that could quietly reintroduce state: Carbon substitutes
     * "now" into *partial* inputs, and `App\Support` is required to be
     * deterministic. The `support does not reach for state` arch test passes
     * either way — it bans the `now` helper, not a parse that consults the clock
     * — so this has to be measured.
     */
    $fingerprint = static fn (): array => array_map(
        static fn ($period): array => [$period->periodStart->toDateString(), $period->days, $period->gross->minor],
        AccrualSchedule::forTerm(CarbonImmutable::parse('2024-01-31', 'UTC'), 12, Money::of(300_000, 'EGP'))->periods,
    );

    $baseline = $fingerprint();
    $processTimezone = date_default_timezone_get();

    try {
        /** A test-now in another zone, at a time of day that would show if it leaked in. */
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2030-06-15 17:45:31', 'Africa/Cairo'));

        expect($fingerprint())->toBe($baseline, 'The schedule moved when the clock was mocked.');

        foreach (['Africa/Cairo', 'Pacific/Kiritimati', 'America/Anchorage'] as $zone) {
            date_default_timezone_set($zone);

            expect($fingerprint())->toBe($baseline, "The schedule moved when the process timezone was {$zone}.");
        }
    } finally {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($processTimezone);
    }

    expect($baseline[0])->toBe(['2024-01-31', 29, 23_770]);
});
