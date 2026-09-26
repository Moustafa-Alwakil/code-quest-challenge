<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use App\Support\Ledger\BalanceDelta;
use Illuminate\Database\Connection;
use Tests\Support\Interleaved;
use Tests\Support\LedgerPostings;

/*
 * The edge-case row F03 leaves to this file: "concurrent postings to one
 * instructor — serialized by the row lock taken by the UPDATE".
 *
 * These are the only tests in the suite that are not transaction-wrapped
 * (R11). Under RefreshDatabase session B cannot see session A's uncommitted
 * rows, so no lock is ever contended and the test passes having proven
 * nothing. DatabaseTruncation commits for real, and the two sessions below
 * genuinely queue behind each other in InnoDB.
 */

beforeEach(function (): void {
    Interleaved::open();
});

afterEach(function (): void {
    Interleaved::close();
    assertLedgerBalanced();
});

/**
 * The same business fact, built fresh each time so no state leaks between
 * sessions: only the ledger's unique key decides who wins.
 */
function samePosting(int $instructorId): Closure
{
    return fn (): bool => app(LedgerService::class)->post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructorId,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($instructorId, 7_000),
    );
}

function postRecognition(int $periodId, int $instructorId, int $instructorMinor): bool
{
    return app(LedgerService::class)->post(
        LedgerPostings::recognition(
            periodId: $periodId,
            subscriptionId: 1,
            instructorId: $instructorId,
            grossMinor: $instructorMinor * 2,
            instructorMinor: $instructorMinor,
        ),
        LedgerPostings::recognizedAndReleased($instructorId, $instructorMinor),
    );
}

it('lets only one of two sessions racing the identical posting write it', function (): void {
    $instructor = Instructor::factory()->create();
    $posting = samePosting($instructor->id);

    /** Session A opens a transaction and writes all three legs, uncommitted. */
    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $posting): void {
        $a->beginTransaction();

        expect($posting())->toBeTrue();
    });

    /**
     * Session B now attempts the very same posting. The unique index is what
     * serializes it: B has to wait for a lock on an index record A holds, and
     * reports the wait rather than writing a second copy.
     */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($posting): void {
        $b->beginTransaction();
        $posting();
    });

    expect($blocked)->not->toBeNull('Session B wrote through a lock session A was holding.')
        ->and($blocked->getMessage())->toContain('Lock wait timeout');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    /** With A committed, B's retry sees the rows and is a plain replay. */
    $replayed = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): bool => $b->transaction($posting),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($replayed)->toBeFalse()
        ->and(LedgerEntry::query()->count())->toBe(3)
        ->and(LedgerEntry::query()->distinct()->count('transaction_uuid'))->toBe(1)
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(7_000);
});

it('serializes two different postings that increment one instructor balance row', function (): void {
    $instructor = Instructor::factory()->create();

    /** A committed first posting, so the snapshot row exists and the contention is the UPDATE itself. */
    DB::transaction(fn (): bool => postRecognition(1, $instructor->id, 7_000));

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $instructor): void {
        $a->beginTransaction();

        expect(postRecognition(2, $instructor->id, 5_000))->toBeTrue();
    });

    /**
     * B's legs do not collide — a different period — so the ledger insert
     * would go through. What stops it is the exclusive lock A's atomic
     * increment holds on this instructor's snapshot row.
     */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($instructor): void {
        $b->beginTransaction();
        postRecognition(3, $instructor->id, 3_000);
    });

    expect($blocked)->not->toBeNull('Two sessions incremented one balance row at the same time.')
        ->and($blocked->getMessage())->toContain('Lock wait timeout');

    /** B rolled its whole transaction back, so the period-3 legs are gone with it. */
    expect(LedgerEntry::query()->where('reference_id', 3)->exists())->toBeFalse();

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): bool => $b->transaction(fn (): bool => postRecognition(3, $instructor->id, 3_000)),
    );

    /** Neither increment was lost: the balance is the sum of all three. */
    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($balance->earned_minor)->toBe(15_000)
        ->and($balance->available_minor)->toBe(15_000)
        ->and(LedgerEntry::query()->count())->toBe(9);
});

it('creates exactly one snapshot row when two sessions post for a brand new instructor', function (): void {
    $instructor = Instructor::factory()->create();

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $instructor): void {
        $a->beginTransaction();

        expect(postRecognition(1, $instructor->id, 7_000))->toBeTrue();
    });

    /** The snapshot row does not exist yet for anyone else: B's insertOrIgnore of it must wait. */
    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($instructor): void {
        $b->beginTransaction();
        postRecognition(2, $instructor->id, 3_000);
    });

    /**
     * The message is asserted, not only the presence of an exception:
     * `expectBlocked()` catches PDOException, so without it a connection error
     * would read as "the sessions serialized".
     */
    expect($blocked)->not->toBeNull('Two sessions created the same snapshot row at the same time.')
        ->and($blocked->getMessage())->toMatch('/Lock wait timeout|Duplicate entry|Deadlock/');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): bool => $b->transaction(fn (): bool => postRecognition(2, $instructor->id, 3_000)),
    );

    expect(InstructorBalance::query()->where('instructor_id', $instructor->id)->count())->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->earned_minor)->toBe(10_000);
});

it('increments from the committed row, not from the value its own snapshot read', function (): void {
    $instructor = Instructor::factory()->create();

    DB::transaction(fn (): bool => postRecognition(1, $instructor->id, 7_000));

    $b = Interleaved::session(Interleaved::SESSION_B);

    /**
     * B opens a transaction and reads the row, fixing its REPEATABLE READ
     * snapshot at 7 000 before A changes anything.
     */
    $snapshotRead = Interleaved::as(Interleaved::SESSION_B, function (Connection $connection) use ($instructor): int {
        $connection->beginTransaction();

        /** @var object{earned_minor: int} $row */
        $row = $connection->table('instructor_balances')->where('instructor_id', $instructor->id)->first();

        return (int) $row->earned_minor;
    });

    expect($snapshotRead)->toBe(7_000);

    /** A posts and commits entirely inside B's open transaction. */
    Interleaved::as(
        Interleaved::SESSION_A,
        fn (Connection $a): bool => $a->transaction(fn (): bool => postRecognition(2, $instructor->id, 5_000)),
    );

    /** B now applies its own delta. Its snapshot still says 7 000; the row says 12 000. */
    Interleaved::as(Interleaved::SESSION_B, function (Connection $connection) use ($instructor): void {
        postRecognition(3, $instructor->id, 3_000);
        $connection->commit();
    });

    /**
     * 15 000 only if the write was `earned_minor = earned_minor + 3000`,
     * evaluated against the committed row. A read-modify-write in PHP would
     * have written 7 000 + 3 000 and silently dropped A's 5 000 — the exact
     * bug the "atomic SQL increments" rule exists to prevent.
     */
    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($balance->earned_minor)->toBe(15_000)
        ->and($balance->available_minor)->toBe(15_000);
});

it('lets the loser write the posting when the winner rolls back', function (): void {
    $instructor = Instructor::factory()->create();
    $posting = samePosting($instructor->id);

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $posting): void {
        $a->beginTransaction();

        expect($posting())->toBeTrue();
    });

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->rollBack());

    /**
     * Nothing survives a rolled-back posting — not a row, not a phantom
     * idempotency key — so the second session's attempt is a *new* posting,
     * not a replay. This is the failure mode that would silently lose money if
     * `post()` cached its decision anywhere but the table.
     */
    $posted = Interleaved::as(
        Interleaved::SESSION_B,
        fn (Connection $b): bool => $b->transaction($posting),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($posted)->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe(3)
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(7_000);
});

it('applies a clawback and a reservation racing on one row without losing either', function (): void {
    $instructor = Instructor::factory()->create();

    DB::transaction(fn (): bool => postRecognition(1, $instructor->id, 10_000));

    $a = Interleaved::session(Interleaved::SESSION_A);

    Interleaved::as(Interleaved::SESSION_A, function () use ($a, $instructor): void {
        $a->beginTransaction();

        app(LedgerService::class)->post(
            LedgerPostings::reservation(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 6_000),
            BalanceDelta::reserved($instructor->id, 6_000),
        );
    });

    $blocked = Interleaved::expectBlocked(Interleaved::SESSION_B, function (Connection $b) use ($instructor): void {
        $b->beginTransaction();

        app(LedgerService::class)->post(
            LedgerPostings::clawback(refundId: 1, instructorId: $instructor->id, amountMinor: 9_000),
            BalanceDelta::clawedBack($instructor->id, fromHeldMinor: 0, fromAvailableMinor: 9_000),
        );
    });

    expect($blocked)->not->toBeNull()
        ->and($blocked->getMessage())->toMatch('/Lock wait timeout|Duplicate entry|Deadlock/');

    Interleaved::as(Interleaved::SESSION_A, fn () => $a->commit());

    Interleaved::as(Interleaved::SESSION_B, fn (Connection $b) => $b->transaction(fn () => app(LedgerService::class)->post(
        LedgerPostings::clawback(refundId: 1, instructorId: $instructor->id, amountMinor: 9_000),
        BalanceDelta::clawedBack($instructor->id, fromHeldMinor: 0, fromAvailableMinor: 9_000),
    )));

    /** 10 000 earned, 6 000 reserved, 9 000 clawed back: available carries forward negative (D-7). */
    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($balance->earned_minor)->toBe(10_000)
        ->and($balance->reserved_minor)->toBe(6_000)
        ->and($balance->clawed_back_minor)->toBe(9_000)
        ->and($balance->available_minor)->toBe(-5_000)
        ->and($balance->outstandingMinor())->toBe(1_000);
});
