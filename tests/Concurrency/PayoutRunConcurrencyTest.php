<?php

declare(strict_types=1);

use App\Actions\Payouts\ReserveInstructorBalanceAction;
use App\DTOs\Payouts\ReserveInstructorBalanceData;
use App\Models\AccrualPeriod;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use App\Models\Plan;
use App\Models\User;
use App\Services\PayoutRunService;
use App\Support\Payouts\ReservationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Interleaved;

/*
 * Video scenario 2: two terminals, at once, on the same run.
 *
 * Not transaction-wrapped (R11) — under RefreshDatabase neither session could
 * see the other's uncommitted item, so the unique index would never be
 * contended and the race under test would not happen.
 *
 * No lock is taken anywhere in this file. Everything that stops the second
 * payment here is a database constraint: UNIQUE `run_key`, UNIQUE
 * `(payout_run_id, instructor_id)`, and the `FOR UPDATE` on the balance row
 * that makes "reserve what is available" a single decision rather than two
 * reads of the same number.
 */

beforeEach(function (): void {
    Interleaved::open();
});

afterEach(function (): void {
    Interleaved::close();
    assertLedgerBalanced();
});

/**
 * An instructor with a real released balance, and an open run to pay it from.
 *
 * @return array{0: Instructor, 1: PayoutRun, 2: int}
 */
function raceablePayout(): array
{
    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => 300_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_payout_race_0001',
        capturedAt: CarbonImmutable::now()->subMonths(11),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);

    $runs = app(PayoutRunService::class);
    $runs->createIfAbsent('payout:race', CarbonImmutable::now(), CarbonImmutable::now(), 'EGP');

    $run = $runs->findByKey('payout:race');

    return [$instructor, $run, InstructorBalance::query()->findOrFail($instructor->id)->available_minor];
}

function reserveFor(int $runId, int $instructorId): ReservationOutcome
{
    return app(ReserveInstructorBalanceAction::class)(
        ReserveInstructorBalanceData::forInstructor($runId, $instructorId, 0, 'EGP')
    );
}

it('lets only one of two sessions reserve the same instructor in one run', function (): void {
    [$instructor, $run, $available] = raceablePayout();

    $a = Interleaved::session(Interleaved::SESSION_A);

    /** A reserves the balance and holds both the snapshot row and the item, uncommitted. */
    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $run, $instructor, $available): void {
        $a->beginTransaction();

        $outcome = reserveFor($run->id, $instructor->id);

        expect($outcome->reserved)->toBeTrue()
            ->and($outcome->amountMinor)->toBe($available);
    });

    /**
     * B wants the same instructor in the same run. It blocks on the `FOR
     * UPDATE` A holds — before it ever reaches the unique index, which is the
     * right order: the amount must not be read while another transaction is
     * deciding it.
     */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($run, $instructor): void {
        $b->beginTransaction();
        reserveFor($run->id, $instructor->id);
    });

    expect($blocked)->not->toBeNull('Session B reserved a balance session A was already reserving.')
        ->and($blocked->getMessage())->toContain('Lock wait timeout');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** With A committed, B sees an available balance of 0 and skips. */
    $second = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): ReservationOutcome => $b->transaction(fn (): ReservationOutcome => reserveFor($run->id, $instructor->id)),
    );

    expect($second->reserved)->toBeFalse()
        ->and($second->skippedReason)->toBe(ReservationOutcome::REASON_BELOW_MINIMUM);

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect(PayoutItem::query()->count())->toBe(1)
        ->and(PayoutItem::query()->firstOrFail()->amount_minor)->toBe($available)
        ->and(LedgerEntry::query()->where('entry_type', 'payout_reserved')->count())->toBe(2)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->reserved_minor)->toBe($available);
});

it('lets the loser reserve when the winning session rolls back', function (): void {
    [$instructor, $run, $available] = raceablePayout();

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $run, $instructor): void {
        $a->beginTransaction();

        expect(reserveFor($run->id, $instructor->id)->reserved)->toBeTrue();
    });

    /** The winner crashes before committing: nothing it did survives. */
    Interleaved::as(Interleaved::SESSION_A, fn () => $a->rollBack());

    $retry = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): ReservationOutcome => $b->transaction(fn (): ReservationOutcome => reserveFor($run->id, $instructor->id)),
    );

    expect($retry->reserved)->toBeTrue()
        ->and($retry->amountMinor)->toBe($available)
        ->and(PayoutItem::query()->count())->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->reserved_minor)->toBe($available);
});

it('opens one run when two sessions race the same key', function (): void {
    $runs = app(PayoutRunService::class);
    $now = CarbonImmutable::now();

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $runs, $now): void {
        $a->beginTransaction();

        expect($runs->createIfAbsent('payout:duplicate', $now, $now, 'EGP'))->toBe(1);
    });

    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($runs, $now): void {
        $b->beginTransaction();
        $runs->createIfAbsent('payout:duplicate', $now, $now, 'EGP');
    });

    expect($blocked)->not->toBeNull('Session B inserted a run key session A was already inserting.');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** B's retry is swallowed by the unique index and finds the winner's run. */
    $written = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): int => $b->transaction(fn (): int => $runs->createIfAbsent('payout:duplicate', $now, $now, 'EGP')),
    );

    expect($written)->toBe(0)
        ->and(PayoutRun::query()->where('run_key', 'payout:duplicate')->count())->toBe(1);
});
