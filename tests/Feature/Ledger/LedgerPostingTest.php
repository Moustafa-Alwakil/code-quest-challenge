<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Support\Ledger\BalanceDelta;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerPostings;

/*
 * Posting is the one write path into the ledger, and its idempotency comes from
 * the UNIQUE index, not from a lock: every assertion below would hold with the
 * cache server switched off.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('writes every leg of a new transaction and moves the snapshot once', function (): void {
    $instructor = Instructor::factory()->create();

    $posted = LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($posted)->toBeTrue()
        ->and(LedgerEntry::query()->count())->toBe(3)
        ->and(LedgerEntry::query()->distinct()->count('transaction_uuid'))->toBe(1)
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(7_000)
        ->and($balance->held_minor)->toBe(0)
        ->and($balance->currency)->toBe('EGP')
        ->and($balance->last_ledger_entry_id)->toBe(LedgerEntry::query()->max('id'));
});

it('treats a replayed transaction as a no-op: one set of rows, one snapshot change', function (): void {
    $instructor = Instructor::factory()->create();

    $transaction = fn () => LedgerPostings::recognition(
        periodId: 1,
        subscriptionId: 1,
        instructorId: $instructor->id,
        grossMinor: 10_000,
        instructorMinor: 7_000,
    );

    $first = LedgerPostings::post($transaction(), LedgerPostings::recognizedAndReleased($instructor->id, 7_000));
    $second = LedgerPostings::post($transaction(), LedgerPostings::recognizedAndReleased($instructor->id, 7_000));

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and(LedgerEntry::query()->count())->toBe(3)
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(7_000);
});

it('upserts a snapshot row the first time an instructor earns anything', function (): void {
    $instructor = Instructor::factory()->create();

    expect(InstructorBalance::query()->whereKey($instructor->id)->exists())->toBeFalse();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
    );

    expect(InstructorBalance::query()->whereKey($instructor->id)->exists())->toBeTrue();
});

it('lets available go negative when a clawback outruns the balance (D-7)', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::clawback(refundId: 1, instructorId: $instructor->id, amountMinor: 4_200),
        BalanceDelta::clawedBack($instructor->id, fromHeldMinor: 0, fromAvailableMinor: 4_200),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($balance->available_minor)->toBe(-4_200)
        ->and($balance->clawed_back_minor)->toBe(4_200)
        ->and($balance->outstandingMinor())->toBe(-4_200);
});

it('carries money through reserve and settle without losing a piastre', function (): void {
    $instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($instructor->id, 7_000),
    );

    LedgerPostings::post(
        LedgerPostings::reservation(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 7_000),
        BalanceDelta::reserved($instructor->id, 7_000),
    );

    $reserved = InstructorBalance::query()->findOrFail($instructor->id);

    expect($reserved->available_minor)->toBe(0)
        ->and($reserved->reserved_minor)->toBe(7_000)
        ->and($reserved->paid_minor)->toBe(0);

    LedgerPostings::post(
        LedgerPostings::settlement(payoutItemId: 1, instructorId: $instructor->id, amountMinor: 7_000),
        BalanceDelta::settled($instructor->id, 7_000),
    );

    $settled = InstructorBalance::query()->findOrFail($instructor->id);

    expect($settled->reserved_minor)->toBe(0)
        ->and($settled->paid_minor)->toBe(7_000)
        ->and($settled->outstandingMinor())->toBe(0);
});

it('touches instructor rows in ascending id order, whatever order the deltas arrive in', function (): void {
    $first = Instructor::factory()->create();
    $second = Instructor::factory()->create();

    $updated = [];

    DB::listen(function ($query) use (&$updated): void {
        if (str_starts_with($query->sql, 'update `instructor_balances`')) {
            $updated[] = (int) end($query->bindings);
        }
    });

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $second->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        // Deliberately the wrong way round: the service must reorder them.
        LedgerPostings::recognizedAndReleased($second->id, 7_000),
        LedgerPostings::recognizedAndReleased($first->id, 0),
    );

    expect($updated)->toBe([$first->id, $second->id]);
});

it('merges two deltas for one instructor into a single row update', function (): void {
    $instructor = Instructor::factory()->create();

    $updates = 0;

    DB::listen(function ($query) use (&$updates): void {
        if (str_starts_with($query->sql, 'update `instructor_balances`')) {
            $updates++;
        }
    });

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        BalanceDelta::recognized($instructor->id, 7_000),
        BalanceDelta::released($instructor->id, 7_000),
    );

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($updates)->toBe(1)
        ->and($balance->earned_minor)->toBe(7_000)
        ->and($balance->available_minor)->toBe(7_000)
        ->and($balance->held_minor)->toBe(0);
});
