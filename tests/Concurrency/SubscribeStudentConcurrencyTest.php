<?php

declare(strict_types=1);

use App\Actions\Subscriptions\SubscribeStudentAction;
use App\DTOs\Subscriptions\SubscribeStudentData;
use App\Models\AccrualPeriod;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Subscriptions\SubscriptionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Tests\Support\Interleaved;

/*
 * The edge-case row F04 leaves to this file: "same payment recorded
 * concurrently -> UNIQUE external_ref: one wins, the other replays".
 *
 * The replay check in step 1 of the action cannot see another transaction's
 * uncommitted payment, so under concurrency it *always* misses and both sessions
 * proceed. That is by design: what stops the second one is the unique index, and
 * this file is the proof. Not transaction-wrapped (R11) — under RefreshDatabase
 * session B could never see session A's rows and the race would never happen.
 */

beforeEach(function (): void {
    Interleaved::open();
});

afterEach(function (): void {
    Interleaved::close();
    assertLedgerBalanced();
});

/**
 * The same captured payment, built fresh for each session so nothing but the
 * database decides who wins.
 */
function raceToRecordPayment(int $userId, int $planId, string $externalRef = 'ch_race_0001'): SubscriptionOutcome
{
    return app(SubscribeStudentAction::class)(SubscribeStudentData::forCapturedPayment(
        userId: $userId,
        planId: $planId,
        externalRef: $externalRef,
        amountMinor: 300_000,
        currency: 'EGP',
        capturedAt: CarbonImmutable::parse('2024-01-31 23:00:00'),
    ));
}

it('lets only one of two sessions racing the same external_ref record it', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    /** Session A records the payment and holds it uncommitted. */
    $a = Interleaved::session(Interleaved::SESSION_A);

    $winner = Interleaved::as(Interleaved::SESSION_A, function () use ($a, $user, $plan): SubscriptionOutcome {
        $a->beginTransaction();

        return raceToRecordPayment($user->id, $plan->id);
    });

    expect($winner->replayed)->toBeFalse();

    /**
     * Session B gets past the replay check — it cannot see A's uncommitted
     * payment — and is stopped by the unique index on `external_ref`, which
     * makes it wait for a lock A holds rather than writing a second copy.
     */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($user, $plan): void {
        $b->beginTransaction();
        raceToRecordPayment($user->id, $plan->id);
    });

    expect($blocked)->not->toBeNull('Session B recorded the same payment a second time.')
        ->and($blocked->getMessage())->toContain('Lock wait timeout');

    /**
     * The race is real, not an artefact of the test: A holds one uncommitted
     * subscription that its own session can see and B's cannot, which is exactly
     * why B's replay check missed and why the unique index had to be what stopped
     * it. The default connection, which committed nothing, sees nothing.
     */
    expect(Interleaved::as(Interleaved::SESSION_A, fn (): int => Subscription::query()->count()))
        ->toBe(1, 'Session A cannot see its own uncommitted subscription.')
        ->and(Interleaved::as(Interleaved::SESSION_B, fn (): int => Subscription::query()->count()))
        ->toBe(0, 'Session B could see session A\'s uncommitted subscription, so the replay check would have caught it and the index was never under test.')
        ->and(Payment::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** With A committed, B's retry takes the replay path and writes nothing. */
    $loser = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): SubscriptionOutcome => $b->transaction(fn (): SubscriptionOutcome => raceToRecordPayment($user->id, $plan->id)),
    );

    expect($loser->replayed)->toBeTrue()
        ->and($loser->subscriptionId)->toBe($winner->subscriptionId)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(AccrualPeriod::query()->count())->toBe(12)
        ->and((int) AccrualPeriod::query()->sum('gross_minor'))->toBe(300_000)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(LedgerEntry::query()->distinct()->count('transaction_uuid'))->toBe(1);
});

it('lets the loser record the payment when the winner rolls back', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $user, $plan): void {
        $a->beginTransaction();

        expect(raceToRecordPayment($user->id, $plan->id)->replayed)->toBeFalse();
    });

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->rollBack());

    /**
     * Nothing survives a rolled-back attempt — no subscription, no payment, no
     * phantom idempotency key — so session B's attempt is a *new* recording,
     * not a replay. This is the failure mode that would lose a real payment if
     * the action remembered its decision anywhere but the table.
     */
    $recorded = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): SubscriptionOutcome => $b->transaction(fn (): SubscriptionOutcome => raceToRecordPayment($user->id, $plan->id)),
    );

    expect($recorded->replayed)->toBeFalse()
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(AccrualPeriod::query()->count())->toBe(12)
        ->and(LedgerEntry::query()->count())->toBe(2);
});

it('keeps two different payments racing each other independent', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->annual()->create();

    $a = Interleaved::session(Interleaved::SESSION_A);

    $first = Interleaved::as(Interleaved::SESSION_A, function () use ($a, $user, $plan): SubscriptionOutcome {
        $a->beginTransaction();

        return raceToRecordPayment($user->id, $plan->id, 'ch_race_0001');
    });

    /** A different external_ref touches a different index record, so nothing blocks. */
    $second = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): SubscriptionOutcome => $b->transaction(
            fn (): SubscriptionOutcome => raceToRecordPayment($user->id, $plan->id, 'ch_race_0002'),
        ),
    );

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    expect($second->subscriptionId)->not->toBe($first->subscriptionId)
        ->and(Subscription::query()->count())->toBe(2)
        ->and(Payment::query()->count())->toBe(2)
        ->and(AccrualPeriod::query()->count())->toBe(24)
        ->and(LedgerEntry::query()->count())->toBe(4);
});
