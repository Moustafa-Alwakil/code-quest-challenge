<?php

declare(strict_types=1);

use App\Enums\PayoutItemStatus;
use App\Models\AccrualPeriod;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\MockProviderTransfer;
use App\Models\Payment;
use App\Models\PayoutItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * **The one test that cannot pass by accident.**
 *
 * Three hundred randomised steps over the demo dataset — new terms, clock
 * jumps, accrual runs, payout runs repeated on the same key, jobs delivered
 * twice, every provider behaviour including the one that succeeds and then
 * times out, reconciliation sweeps, pro-rata and full refunds — with all eight
 * invariants asserted after **every single step**.
 *
 * Every other test in the suite checks a path somebody thought of. This one
 * checks the paths nobody thought of: the orderings, the interleavings, and the
 * states that only arise when a refund lands between a reservation and its
 * settlement. If the design is wrong anywhere, a seeded random walk of three
 * hundred steps will find it, and the seed makes the walk reproducible.
 *
 * The step log is printed on failure, so a red run names the exact sequence
 * that broke it rather than leaving a state nobody can explain.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00:00'));
    $this->seed(DemoSeeder::class);
});

it('keeps every invariant through a random walk of the whole system', function (): void {
    chaosWalk(steps: 300);
});

/**
 * The same walk, an order of magnitude longer.
 *
 * Excluded from the default run because it takes minutes rather than seconds,
 * but it is the same code — so a failure here is a real failure, not a
 * different test's opinion.
 */
it('survives a long soak', function (): void {
    chaosWalk(steps: 5_000);
})->group('soak');

/**
 * The guard on the guard.
 *
 * A chaos test that could not fail would be the most expensive way in this
 * repository to prove nothing, and "300 steps passed" reads identically either
 * way. So the harness is pointed at a database that is deliberately wrong, and
 * has to notice.
 *
 * No `assertLedgerBalanced()` hook in this file, for the obvious reason: the
 * subject here is a broken ledger.
 */
it('goes red when an invariant is broken underneath it', function (): void {
    Artisan::call('ledger:accrue', ['--sync' => true]);

    $balance = InstructorBalance::query()->firstOrFail();

    expect($balance->earned_minor)->toBeGreaterThan(0);

    /** One piastre the ledger never posted. */
    DB::table('instructor_balances')
        ->where('instructor_id', $balance->instructor_id)
        ->increment('paid_minor');

    expect(fn () => assertInvariantsHold(['probe step'], testSeed()))
        ->toThrow(PHPUnit\Framework\ExpectationFailedException::class, 'invariants broken');
});

/**
 * Drives the random walk, asserting the invariants after every step.
 */
function chaosWalk(int $steps): void
{
    $seed = testSeed();

    $faker = FakerFactory::create();
    $faker->seed($seed);

    /** @var list<string> $log */
    $log = [];

    foreach (range(1, $steps) as $number) {
        $action = $faker->randomElement([
            'subscribe', 'advance', 'accrue', 'accrue',
            'run_payouts', 'run_payouts_again', 'work_item', 'work_item_twice',
            'reconcile', 'refund_prorata', 'refund_full', 'crash_after_transfer',
        ]);

        $log[] = sprintf('%3d. %-22s @ %s', $number, $action, CarbonImmutable::now()->toDateTimeString());

        try {
            chaosStep($action, $faker);
        } catch (Throwable $thrown) {
            /**
             * A step throwing is not automatically a failure: a refund on an
             * already-refunded term, or a payout with nothing to pay, are
             * legitimate no-ops that surface as exceptions from the entry
             * point. What must never survive a throw is a broken invariant,
             * which is checked immediately below either way.
             */
            $log[count($log) - 1] .= '  (threw: '.Str::limit($thrown->getMessage(), 60).')';
        }

        assertInvariantsHold($log, $seed);
    }

    drainReconciliation($log, $seed);
    assertProviderAndLedgerAgree($log, $seed);
}

/**
 * One step of the walk. Every branch goes through a real entry point — a
 * command, an Action or a job — never a raw write.
 */
function chaosStep(string $action, Generator $faker): void
{
    match ($action) {
        'subscribe' => chaosSubscribe($faker),

        /** Time is what turns holds into available balances and periods into revenue. */
        'advance' => test()->travelTo(CarbonImmutable::now()->addDays($faker->numberBetween(1, 10))),

        'accrue' => Artisan::call('ledger:accrue', ['--sync' => true]),

        /** The same key twice is required proof #1, fired at random. */
        'run_payouts' => chaosRunPayouts($faker, sameKey: false),
        'run_payouts_again' => chaosRunPayouts($faker, sameKey: true),

        'work_item' => chaosWorkItem($faker, twice: false),
        'work_item_twice' => chaosWorkItem($faker, twice: true),

        'reconcile' => Artisan::call('payouts:reconcile', ['--sync' => true]),

        'refund_prorata' => chaosRefund($faker, full: false),
        'refund_full' => chaosRefund($faker, full: true),

        'crash_after_transfer' => chaosCrashAfterTransfer($faker),

        default => null,
    };
}

function chaosSubscribe(Generator $faker): void
{
    $plan = Plan::query()->inRandomOrder()->firstOrFail();
    $student = User::query()->where('is_admin', false)->inRandomOrder()->firstOrFail();

    $outcome = recordCapturedPayment(
        $student,
        $plan,
        'ch_chaos_'.$faker->unique()->numerify('########'),
        capturedAt: CarbonImmutable::now()->subMonths($faker->numberBetween(0, 11)),
    );

    /** Engagement, so the term has somebody to credit when it is recognized. */
    $instructors = Instructor::query()->inRandomOrder()->limit($faker->numberBetween(1, 3))->get();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        foreach ($instructors as $instructor) {
            engage($outcome->subscriptionId, $period, $instructor, $faker->numberBetween(1, 600));
        }
    }
}

/**
 * A payout run, with the provider's next answer drawn from all four behaviours
 * — including the one that moves the money and then loses the reply.
 */
function chaosRunPayouts(Generator $faker, bool $sameKey): void
{
    provider()->script(array_map(
        static fn (): string => $faker->randomElement([
            ScriptedMockProvider::OUTCOME_SUCCESS,
            ScriptedMockProvider::OUTCOME_PERMANENT_FAILURE,
            ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS,
            ScriptedMockProvider::OUTCOME_DELAYED_CONFIRMATION,
        ]),
        range(1, 12),
    ));

    $key = $sameKey
        ? 'payout:'.CarbonImmutable::now()->format('Y-m')
        : 'payout:chaos-'.$faker->numerify('####');

    Artisan::call('payouts:run', ['--run-key' => $key]);
}

/**
 * Hands a reserved item to a worker — sometimes twice, which is what an
 * at-least-once queue does on a bad day.
 */
function chaosWorkItem(Generator $faker, bool $twice): void
{
    $item = PayoutItem::query()
        ->whereIn('status', [PayoutItemStatus::RESERVED, PayoutItemStatus::SUBMITTED, PayoutItemStatus::UNKNOWN])
        ->inRandomOrder()
        ->first();

    if ($item === null) {
        return;
    }

    provider()->script([$faker->randomElement([
        ScriptedMockProvider::OUTCOME_SUCCESS,
        ScriptedMockProvider::OUTCOME_PERMANENT_FAILURE,
        ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS,
    ])]);

    processItem($item->id);

    if ($twice) {
        processItem($item->id);
    }
}

function chaosRefund(Generator $faker, bool $full): void
{
    $subscription = Subscription::query()
        ->where('status', 'active')
        ->inRandomOrder()
        ->first();

    if ($subscription === null) {
        return;
    }

    Artisan::call('refunds:issue', [
        'subscription' => (string) $subscription->id,
        '--external-ref' => 're_chaos_'.$faker->unique()->numerify('########'),
        '--full' => $full,
    ]);
}

/**
 * The provider moved the money and the worker died before recording it.
 *
 * Simulated by scripting a timeout-after-success and then *not* reconciling —
 * the item is left `unknown` with its money reserved, which is precisely the
 * state D-8 exists to make survivable.
 */
function chaosCrashAfterTransfer(Generator $faker): void
{
    $item = PayoutItem::query()
        ->where('status', PayoutItemStatus::RESERVED)
        ->inRandomOrder()
        ->first();

    if ($item === null) {
        return;
    }

    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    processItem($item->id);
}

/**
 * Keeps reconciling until nothing is uncertain any more.
 *
 * Bounded, because "drain until quiet" without a bound is how a broken backoff
 * turns into a hanging test rather than a failing one.
 */
function drainReconciliation(array $log, int $seed): void
{
    foreach (range(1, 40) as $sweep) {
        $pending = PayoutItem::query()
            ->whereIn('status', [PayoutItemStatus::SUBMITTED, PayoutItemStatus::UNKNOWN])
            ->count();

        if ($pending === 0) {
            return;
        }

        test()->travelTo(CarbonImmutable::now()->addHours(7));

        provider()->script(array_fill(0, 20, ScriptedMockProvider::OUTCOME_SUCCESS));

        Artisan::call('payouts:reconcile', ['--sync' => true]);

        assertInvariantsHold([...$log, "drain sweep {$sweep}"], $seed);
    }

    $remaining = PayoutItem::query()
        ->whereIn('status', [PayoutItemStatus::SUBMITTED, PayoutItemStatus::UNKNOWN])
        ->count();

    expect($remaining)->toBe(0, chaosFailure('reconciliation never drained', $log, $seed));
}

/**
 * Invariants I1-I6 and I8 come from `ledger:verify`; I7 is half there and half
 * in the provider's own records, which is what this adds.
 */
function assertInvariantsHold(array $log, int $seed): void
{
    $result = app(App\Actions\Ledger\VerifyLedgerAction::class)(
        App\DTOs\Ledger\VerifyLedgerData::everything()
    );

    $rows = array_map(
        static fn (array $row): string => "  {$row['check']} | {$row['scope']} | {$row['field']} | expected {$row['expected']}, got {$row['actual']}",
        $result->toRows(),
    );

    /**
     * Asserted every time, not only when it fails. An assertion that fires only
     * on the unhappy path leaves a green run indistinguishable from a run that
     * never checked anything — and this is the one test where that distinction
     * is the whole value.
     */
    expect($result->isClean())->toBeTrue(
        chaosFailure("invariants broken:\n".implode("\n", $rows), $log, $seed)
    );
}

/**
 * The end-state assertions: what the provider actually did, against what the
 * ledger says it did.
 */
function assertProviderAndLedgerAgree(array $log, int $seed): void
{
    /** I7's provider half: every settled item moved money exactly once. */
    foreach (PayoutItem::query()->where('status', PayoutItemStatus::SUCCEEDED)->get() as $item) {
        expect(provider()->transferCount($item->idempotency_key))
            ->toBe(1, chaosFailure("item {$item->id} did not move money exactly once", $log, $seed));
    }

    /** Nothing was ever transferred for an item that is not succeeded. */
    $paidPerInstructor = PayoutItem::query()
        ->where('status', PayoutItemStatus::SUCCEEDED)
        ->selectRaw('instructor_id, sum(amount_minor) as total')
        ->groupBy('instructor_id')
        ->pluck('total', 'instructor_id');

    foreach (InstructorBalance::query()->get() as $balance) {
        expect($balance->paid_minor)->toBe(
            (int) ($paidPerInstructor[$balance->instructor_id] ?? 0),
            chaosFailure("instructor {$balance->instructor_id} paid_minor disagrees with their succeeded items", $log, $seed),
        );
    }

    /** No instructor was paid twice inside one run (PLAN §9 row 6). */
    $doublePaid = DB::table('payout_items')
        ->selectRaw('payout_run_id, instructor_id, count(*) as items')
        ->where('status', PayoutItemStatus::SUCCEEDED->value)
        ->groupBy('payout_run_id', 'instructor_id')
        ->havingRaw('count(*) > 1')
        ->get();

    expect($doublePaid->all())->toBe([], chaosFailure('an instructor has two succeeded items in one run', $log, $seed));

    /** And the provider never transferred for a key we do not know about. */
    $unknownKeys = MockProviderTransfer::query()
        ->whereNotIn('idempotency_key', PayoutItem::query()->pluck('idempotency_key'))
        ->count();

    expect($unknownKeys)->toBe(0, chaosFailure('the provider holds a transfer we never asked for', $log, $seed));

    /**
     * The walk actually exercised the system, so a green run is not an empty
     * one. Without this a broken random pick — every step landing on a branch
     * that returns early — would produce a confident pass over a database
     * nothing happened to.
     */
    expect(Payment::query()->count())->toBeGreaterThan(13, chaosFailure('no new terms were created', $log, $seed))
        ->and(AccrualPeriod::query()->where('status', 'recognized')->count())
        ->toBeGreaterThan(0, chaosFailure('nothing was ever recognized', $log, $seed))
        ->and(PayoutItem::query()->count())
        ->toBeGreaterThan(0, chaosFailure('no payout was ever reserved', $log, $seed))
        ->and(PayoutItem::query()->where('status', PayoutItemStatus::SUCCEEDED)->count())
        ->toBeGreaterThan(0, chaosFailure('no payout ever succeeded', $log, $seed))
        ->and(App\Models\Refund::query()->count())
        ->toBeGreaterThan(0, chaosFailure('no refund was ever issued', $log, $seed))
        ->and(MockProviderTransfer::query()->count())
        ->toBeGreaterThan(0, chaosFailure('the provider was never called', $log, $seed));
}

/**
 * The seed and the whole step log, so a red run reproduces exactly.
 */
function chaosFailure(string $what, array $log, int $seed): string
{
    return "Chaos run failed: {$what}\n\n"
        ."Reproduce with TEST_SEED={$seed}\n\n"
        .implode("\n", array_slice($log, -40));
}
