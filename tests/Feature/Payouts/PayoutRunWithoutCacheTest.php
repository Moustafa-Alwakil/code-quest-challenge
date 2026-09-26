<?php

declare(strict_types=1);

use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use Carbon\CarbonImmutable;
use Illuminate\Cache\NoLock;
use Illuminate\Support\Facades\Cache;

/*
 * The test that backs the strongest claim in PLAN §9: **if Redis disappeared
 * entirely, no instructor would be paid twice.**
 *
 * `Cache::lock()` is bound to a lock that always succeeds, so every invocation
 * below gets past the guard that would normally serialize them. What is left is
 * exactly the set of mechanisms row 10 calls optimizations and rows 5-7 call
 * correctness: UNIQUE `run_key`, UNIQUE `(payout_run_id, instructor_id)`, and
 * reserve-before-send.
 *
 * If this file ever goes red, the lock has quietly become load-bearing.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

beforeEach(function (): void {
    /**
     * Resolved *before* the facade is mocked, and kept. `cache.store` is a
     * singleton defined as `$app['cache']->driver()`, so resolving it after the
     * swap would ask the mock for a driver and get the mock back — which
     * recurses until the process runs out of memory. The queue's own guards
     * resolve the cache through the container, and they need a real store.
     */
    $store = app('cache.store');

    /**
     * `NoLock` is Laravel's own always-acquires lock — the null-cache
     * behaviour, reached here deliberately rather than by turning the cache
     * off and hoping.
     */
    Cache::shouldReceive('lock')
        ->andReturnUsing(fn (string $name, int $seconds = 0): NoLock => new NoLock($name, $seconds));

    Cache::shouldReceive('driver')->andReturn($store);
    Cache::shouldReceive('store')->andReturn($store);
});

it('still pays each instructor once when every lock is granted', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    $instructor = instructorWithAvailableBalance('ch_nolock_0001');
    $available = InstructorBalance::query()->findOrFail($instructor->id)->available_minor;

    /** Three invocations that all believe they hold the lock. */
    $this->artisan('payouts:run', ['--run-key' => 'payout:nolock'])->assertSuccessful();
    $this->artisan('payouts:run', ['--run-key' => 'payout:nolock'])->assertSuccessful();
    $this->artisan('payouts:run', ['--run-key' => 'payout:nolock'])->assertSuccessful();

    $item = PayoutItem::query()->firstOrFail();
    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect(PayoutRun::query()->count())->toBe(1)
        ->and(PayoutItem::query()->count())->toBe(1)
        ->and($item->amount_minor)->toBe($available)
        /** One reservation and one settlement, two legs each. */
        ->and(LedgerEntry::query()->where('entry_type', 'payout_reserved')->count())->toBe(2)
        ->and(LedgerEntry::query()->where('entry_type', 'payout_settled')->count())->toBe(2)
        /** And the provider moved the money exactly once. */
        ->and(provider()->transferCount($item->idempotency_key))->toBe(1)
        ->and($balance->available_minor)->toBe(0)
        ->and($balance->reserved_minor)->toBe(0)
        ->and($balance->paid_minor)->toBe($available);
});

it('still gives a second run key nothing, because the money already left available', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithAvailableBalance('ch_nolock_0002');

    $this->artisan('payouts:run', ['--run-key' => 'payout:nolock-a'])->assertSuccessful();
    $this->artisan('payouts:run', ['--run-key' => 'payout:nolock-b'])->assertSuccessful();

    /**
     * The unique item index cannot help here — these are different runs, so the
     * pair is different. Only reservation stops the second payment.
     */
    expect(PayoutRun::query()->count())->toBe(2)
        ->and(PayoutItem::query()->count())->toBe(1);
});
