<?php

declare(strict_types=1);

use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Video scenario 7. The pool almost never divides evenly by arbitrary
 * engagement weights, and every piastre of it has to land on somebody.
 *
 * `Allocator::largestRemainder` is unit-tested on its own; what these prove is
 * that recognition *uses* it correctly — same total in, same total out, no
 * second rounding step, and a share that rounds to nothing writes no row rather
 * than a zero one.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * @param  list<int>                                    $weights units per instructor, in ascending instructor id
 * @return array{0: AccrualPeriod, 1: list<Instructor>}
 */
function recognizeWithWeights(string $externalRef, int $priceMinor, array $weights, int $shareBps = 7_000): array
{
    $plan = Plan::factory()->monthly()->create(['price_minor' => $priceMinor]);
    $outcome = recordCapturedPayment(User::factory()->create(), $plan, $externalRef);

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();

    $instructors = [];

    foreach ($weights as $units) {
        $instructor = Instructor::factory()->create();
        $instructors[] = $instructor;

        engage($outcome->subscriptionId, $period, $instructor, $units);
    }

    return [recognizeFirstPeriodOf($outcome->subscriptionId, $shareBps), $instructors];
}

it('places the remainder on the lowest instructor id when three equal weights split a pool', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    /** 100% to the pool, so the pool is exactly 1 000 and 1 000 / 3 is the case D-5 is about. */
    [$period, $instructors] = recognizeWithWeights('ch_round_0001', 1_000, [3, 3, 3], shareBps: 10_000);

    $amounts = EarningAllocation::query()->orderBy('instructor_id')->pluck('amount_minor', 'instructor_id');

    expect($period->pool_minor)->toBe(1_000)
        ->and($period->platform_minor)->toBe(0)
        /** 334 / 333 / 333: the tie-break is ascending key, so the first id wins. */
        ->and($amounts[$instructors[0]->id])->toBe(334)
        ->and($amounts[$instructors[1]->id])->toBe(333)
        ->and($amounts[$instructors[2]->id])->toBe(333)
        ->and(array_sum($amounts->all()))->toBe(1_000);
});

it('writes no row for an instructor whose share rounds to nothing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    /** A pool of 2 across five equal weights: two instructors get a piastre, three get none. */
    [$period, $instructors] = recognizeWithWeights('ch_round_0002', 2, [1, 1, 1, 1, 1], shareBps: 10_000);

    $allocations = EarningAllocation::query()->orderBy('instructor_id')->get();

    expect($period->pool_minor)->toBe(2)
        ->and($allocations)->toHaveCount(2)
        ->and($allocations->sum('amount_minor'))->toBe(2)
        ->and($allocations->pluck('instructor_id')->all())
        ->toBe([$instructors[0]->id, $instructors[1]->id]);

    /**
     * A zero allocation is not a small allocation. It would sit in the
     * maturation sweep forever releasing nothing, and would claim in the audit
     * trail that an instructor earned from a period they earned nothing from.
     */
    expect(EarningAllocation::query()->where('amount_minor', 0)->count())->toBe(0);
});

it('gives the whole pool to a single instructor', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    [$period, $instructors] = recognizeWithWeights('ch_round_0003', 30_000, [7]);

    expect($period->pool_minor)->toBe(21_000)
        ->and(EarningAllocation::query()->where('instructor_id', $instructors[0]->id)->firstOrFail()->amount_minor)
        ->toBe(21_000);
});

it('keeps platform plus allocations equal to gross across awkward weights', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    /** 1 000 003 piastres at 70% is 700 002 to the pool, split 1 : 999 999. */
    [$period] = recognizeWithWeights('ch_round_0004', 1_000_003, [1, 999_999]);

    $allocated = (int) EarningAllocation::query()->sum('amount_minor');

    /** Invariant I6, stated directly — `ledger:verify` check 6 also proves it. */
    expect($period->platform_minor + $allocated)->toBe($period->gross_minor)
        ->and($allocated)->toBe($period->pool_minor);
});
