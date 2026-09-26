<?php

declare(strict_types=1);

use App\Actions\Subscriptions\ExpireSubscriptionsAction;
use App\DTOs\Subscriptions\ExpireSubscriptionsData;
use App\Enums\SubscriptionStatus;
use App\Models\LedgerEntry;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/*
 * The expiry sweep is cosmetic, and these tests are written to keep it that way.
 *
 * Money is driven by `accrual_periods`; a subscription's status is a label for
 * the admin screen. So the assertions are: the label moves for finished terms
 * only, the sweep is idempotent, it batches, and it never writes a ledger row.
 * If a future change made money depend on this column, a missed cron job would
 * become a financial incident — the last assertion in each test is what would go
 * red first.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

function expireSubscriptions(?string $asOf = null, ?string $chunk = null): int
{
    return app(ExpireSubscriptionsAction::class)(ExpireSubscriptionsData::fromCommand($asOf, $chunk));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 08:00:00'));
});

it('expires a term that has ended and leaves a running one alone', function (): void {
    $ended = Subscription::factory()->endedDaysAgo(3)->create();
    $running = Subscription::factory()->create();

    $expired = expireSubscriptions();

    expect($expired)->toBe(1)
        ->and($ended->refresh()->status)->toBe(SubscriptionStatus::EXPIRED)
        ->and($running->refresh()->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and(LedgerEntry::query()->count())->toBe(0);
});

it('expires a term whose exclusive end date is today', function (): void {
    $endsToday = Subscription::factory()->endedDaysAgo(0)->create();

    expect(expireSubscriptions())->toBe(1)
        ->and($endsToday->refresh()->status)->toBe(SubscriptionStatus::EXPIRED);
});

it('touches only active subscriptions, so a refund is never overwritten', function (): void {
    $refunded = Subscription::factory()->endedDaysAgo(10)->refunded()->create();
    $alreadyExpired = Subscription::factory()->endedDaysAgo(10)->expired()->create();

    expect(expireSubscriptions())->toBe(0)
        ->and($refunded->refresh()->status)->toBe(SubscriptionStatus::REFUNDED)
        ->and($refunded->canceled_at)->not->toBeNull()
        ->and($alreadyExpired->refresh()->status)->toBe(SubscriptionStatus::EXPIRED);
});

it('expires nothing on a second run', function (): void {
    Subscription::factory()->count(3)->endedDaysAgo(5)->create();

    expect(expireSubscriptions())->toBe(3)
        ->and(expireSubscriptions())->toBe(0)
        ->and(Subscription::query()->where('status', SubscriptionStatus::EXPIRED)->count())->toBe(3);
});

it('sweeps more terms than one batch holds', function (): void {
    Subscription::factory()->count(5)->endedDaysAgo(1)->create();
    Subscription::factory()->count(2)->create();

    /** Five ended terms in batches of two: 2 + 2 + 1, then a short batch stops the loop. */
    expect(expireSubscriptions(chunk: '2'))->toBe(5)
        ->and(Subscription::query()->where('status', SubscriptionStatus::EXPIRED)->count())->toBe(5)
        ->and(Subscription::query()->where('status', SubscriptionStatus::ACTIVE)->count())->toBe(2);
});

it('treats --as-of as today', function (): void {
    /** A term with three days left today, and therefore finished a week from now. */
    $endsSoon = Subscription::factory()->endedDaysAgo(-3)->create();

    expect(expireSubscriptions())->toBe(0)
        ->and($endsSoon->refresh()->status)->toBe(SubscriptionStatus::ACTIVE);

    expect(expireSubscriptions(asOf: '2024-06-22'))->toBe(1)
        ->and($endsSoon->refresh()->status)->toBe(SubscriptionStatus::EXPIRED);
});

it('reports the count from the console and succeeds', function (): void {
    Subscription::factory()->count(2)->endedDaysAgo(1)->create();

    $this->artisan('subscriptions:expire')
        ->expectsOutputToContain('marked 2 subscription(s) expired as of 2024-06-15')
        ->assertExitCode(0);

    expect(Subscription::query()->where('status', SubscriptionStatus::EXPIRED)->count())->toBe(2);
});

it('succeeds with nothing to do', function (): void {
    $this->artisan('subscriptions:expire')
        ->expectsOutputToContain('marked 0 subscription(s) expired')
        ->assertExitCode(0);
});

it('rejects a chunk size that would never finish', function (): void {
    expect(fn () => expireSubscriptions(chunk: '0'))
        ->toThrow(InvalidArgumentException::class, '--chunk must be a positive integer');
});

it('rejects an unparseable --as-of', function (): void {
    expect(fn () => expireSubscriptions(asOf: 'the day after tomorrow-ish'))
        ->toThrow(InvalidArgumentException::class, '--as-of must be a date');
});

it('defaults to today through the DTO the scheduler builds', function (): void {
    Subscription::factory()->endedDaysAgo(1)->create();

    /** No options: exactly what `subscriptions:expire` passes on its daily run. */
    $expired = app(ExpireSubscriptionsAction::class)(ExpireSubscriptionsData::fromCommand());

    expect($expired)->toBe(1);
});
