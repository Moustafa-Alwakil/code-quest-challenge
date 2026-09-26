<?php

declare(strict_types=1);

use App\Enums\AccrualPeriodStatus;
use App\Support\Refunds\PeriodLine;
use App\Support\Refunds\RefundPlan;
use Carbon\CarbonImmutable;

/*
 * Which periods a refund touches, decided without a database.
 *
 * Every row of F09's edge-case table is a boundary — day one, exactly on a
 * period start, after the term is fully recognized — and boundaries are where
 * a refund quietly gives back a month too many or too few. Testing the
 * decision as a pure function means each one costs three lines.
 */

function period(int $sequence, string $start, string $end, int $gross, AccrualPeriodStatus $status = AccrualPeriodStatus::SCHEDULED): PeriodLine
{
    $periodStart = CarbonImmutable::parse($start, 'UTC');
    $periodEnd = CarbonImmutable::parse($end, 'UTC');

    return new PeriodLine(
        $sequence,
        $sequence,
        $periodStart,
        $periodEnd,
        (int) $periodStart->diffInDays($periodEnd),
        $gross,
        $status,
    );
}

/**
 * A four-month term of 100 00 piastres a month, the first two already earned.
 *
 * @return list<PeriodLine>
 */
function partlyEarnedTerm(): array
{
    return [
        period(1, '2026-01-01', '2026-02-01', 10_000, AccrualPeriodStatus::RECOGNIZED),
        period(2, '2026-02-01', '2026-03-01', 10_000, AccrualPeriodStatus::RECOGNIZED),
        period(3, '2026-03-01', '2026-04-01', 10_000),
        period(4, '2026-04-01', '2026-05-01', 10_000),
    ];
}

function utc(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'UTC');
}

it('truncates the period the refund lands inside and cancels the rest', function (): void {
    $plan = RefundPlan::forProrata(partlyEarnedTerm(), utc('2026-03-16'));

    expect($plan->truncatesAPeriod())->toBeTrue()
        ->and($plan->truncation?->periodId)->toBe(3)
        ->and($plan->truncation?->usedDays)->toBe(15)
        /** 31 days of 10 000: 15 used, 16 unused. */
        ->and($plan->truncation?->usedMinor)->toBe(4_839)
        ->and($plan->truncation?->unusedMinor)->toBe(5_161)
        ->and($plan->cancelledPeriodIds)->toBe([4])
        ->and($plan->cancelledGrossMinor)->toBe(10_000)
        ->and($plan->unearnedMinor())->toBe(15_161);

    /** The split is exact: nothing invented, nothing lost. */
    expect($plan->truncation?->usedMinor + $plan->truncation?->unusedMinor)->toBe(10_000);
});

it('truncates nothing when the refund falls exactly on a period start', function (): void {
    $plan = RefundPlan::forProrata(partlyEarnedTerm(), utc('2026-03-01'));

    /** Half-open periods: the date belongs to the period beginning, not the one ending. */
    expect($plan->truncatesAPeriod())->toBeFalse()
        ->and($plan->cancelledPeriodIds)->toBe([3, 4])
        ->and($plan->unearnedMinor())->toBe(20_000);
});

it('gives the whole price back on day one', function (): void {
    $term = [
        period(1, '2026-01-01', '2026-02-01', 10_000),
        period(2, '2026-02-01', '2026-03-01', 10_000),
    ];

    $plan = RefundPlan::forProrata($term, utc('2026-01-01'));

    expect($plan->truncatesAPeriod())->toBeFalse()
        ->and($plan->cancelledPeriodIds)->toBe([1, 2])
        ->and($plan->unearnedMinor())->toBe(20_000);
});

it('refunds nothing once the term is fully recognized', function (): void {
    $term = [
        period(1, '2026-01-01', '2026-02-01', 10_000, AccrualPeriodStatus::RECOGNIZED),
        period(2, '2026-02-01', '2026-03-01', 10_000, AccrualPeriodStatus::RECOGNIZED),
    ];

    $plan = RefundPlan::forProrata($term, utc('2026-03-15'));

    expect($plan->cancelledPeriodIds)->toBe([])
        ->and($plan->truncatesAPeriod())->toBeFalse()
        ->and($plan->unearnedMinor())->toBe(0)
        ->and($plan->movesUnearnedMoney())->toBeFalse();
});

it('leaves a delivered but unrecognized period for the accrual run to earn', function (): void {
    /** Period 1 has closed and nobody has recognized it yet; the refund is later. */
    $term = [
        period(1, '2026-01-01', '2026-02-01', 10_000),
        period(2, '2026-02-01', '2026-03-01', 10_000),
    ];

    $plan = RefundPlan::forProrata($term, utc('2026-02-10'));

    /**
     * Period 1 is neither cancelled nor truncated: it was delivered in full, so
     * it is still owed to whoever taught it. `ledger:accrue` recognizes it as
     * normal — a refund never quietly cancels time the student used.
     */
    expect($plan->cancelledPeriodIds)->toBe([])
        ->and($plan->truncation?->periodId)->toBe(2)
        ->and($plan->unearnedMinor())->toBe($plan->truncation?->unusedMinor);
});

it('cancels every scheduled period for a full refund, wherever it lands', function (): void {
    $plan = RefundPlan::forFull(partlyEarnedTerm(), utc('2026-03-16'));

    /** No truncation: nothing is kept, so there is nothing to split. */
    expect($plan->truncatesAPeriod())->toBeFalse()
        ->and($plan->cancelledPeriodIds)->toBe([3, 4])
        ->and($plan->unearnedMinor())->toBe(20_000);
});

it('refuses to truncate on a boundary, where there is nothing to split', function (): void {
    expect(fn () => App\Support\Refunds\PeriodTruncation::of(
        period(1, '2026-01-01', '2026-02-01', 10_000),
        utc('2026-01-01'),
    ))->toThrow(InvalidArgumentException::class, 'strictly inside period');
});

it('gives the leftover piastre to the student on an even split', function (): void {
    /** A two-day period worth 3: one day each, and the odd piastre is refunded. */
    $truncation = App\Support\Refunds\PeriodTruncation::of(
        period(1, '2026-01-01', '2026-01-03', 3),
        utc('2026-01-02'),
    );

    expect($truncation->usedMinor)->toBe(1)
        ->and($truncation->unusedMinor)->toBe(2);
});
