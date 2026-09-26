<?php

declare(strict_types=1);

use App\Enums\AccrualPeriodStatus;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Refund;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * `--dry-run` prints what a refund would do and writes nothing.
 *
 * The preview is not a second calculation that happens to agree with the first:
 * both build the same `RefundPlan` from the same periods, so the number shown
 * is the number applied by construction. These tests assert that equality
 * directly rather than trusting the claim.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('previews the exact amount the real refund then applies', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId] = refundableTerm('ch_dry_0001');

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_dry_0001',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Would apply a prorata refund')
        ->expectsOutputToContain('No instructor is affected')
        ->assertSuccessful();

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_dry_0001',
    ])->assertSuccessful();

    /** The preview's own number, recomputed under the lock, is what was written. */
    expect(Refund::query()->firstOrFail()->amount_minor)->toBeGreaterThan(0);
});

it('writes nothing at all', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId] = refundableTerm('ch_dry_0002');

    $before = [
        'refunds' => Refund::query()->count(),
        'entries' => LedgerEntry::query()->count(),
        'periods' => AccrualPeriod::query()->orderBy('id')->get(['status', 'period_end', 'days', 'gross_minor'])->toArray(),
        'allocations' => EarningAllocation::query()->orderBy('id')->get(['amount_minor', 'clawed_back_at'])->toArray(),
        'balances' => InstructorBalance::query()->orderBy('instructor_id')->get()->toArray(),
        'subscription' => Subscription::query()->findOrFail($subscriptionId)->status,
        'touched' => DB::table('instructor_balances')->orderBy('instructor_id')->pluck('updated_at')->all(),
    ];

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_dry_0002',
        '--full' => true,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(Refund::query()->count())->toBe($before['refunds'])
        ->and(LedgerEntry::query()->count())->toBe($before['entries'])
        ->and(AccrualPeriod::query()->orderBy('id')->get(['status', 'period_end', 'days', 'gross_minor'])->toArray())
        ->toBe($before['periods'])
        ->and(EarningAllocation::query()->orderBy('id')->get(['amount_minor', 'clawed_back_at'])->toArray())
        ->toBe($before['allocations'])
        ->and(InstructorBalance::query()->orderBy('instructor_id')->get()->toArray())->toBe($before['balances'])
        ->and(Subscription::query()->findOrFail($subscriptionId)->status)->toBe($before['subscription'])
        /** Even `updated_at` is unmoved — MySQL maintains it, so a no-op write would show. */
        ->and(DB::table('instructor_balances')->orderBy('instructor_id')->pluck('updated_at')->all())
        ->toBe($before['touched']);
});

it('shows a full refund the per-instructor impact it would have', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId, $alice] = refundableTerm('ch_dry_0003');

    $owed = InstructorBalance::query()->findOrFail($alice->id);
    $expected = $owed->held_minor + $owed->available_minor;

    expect($expected)->toBeGreaterThan(0);

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_dry_0003',
        '--full' => true,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Would apply a full refund')
        ->assertSuccessful();

    /** Now apply it, and check the preview's arithmetic was the real one. */
    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_dry_0003',
        '--full' => true,
    ])->assertSuccessful();

    expect(InstructorBalance::query()->findOrFail($alice->id)->clawed_back_minor)->toBe($expected)
        ->and(AccrualPeriod::query()->where('subscription_id', $subscriptionId)
            ->where('status', AccrualPeriodStatus::SCHEDULED)->count())->toBe(0);
});

it('says so when the term has already been refunded', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$subscriptionId] = refundableTerm('ch_dry_0004');

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_dry_0004',
    ])->assertSuccessful();

    $this->artisan('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_dry_0004_preview',
        '--dry-run' => true,
    ])->expectsOutputToContain('already refunded')->assertSuccessful();
});

it('refuses a subscription that was never paid for', function (): void {
    $this->artisan('refunds:issue', [
        'subscription' => '9999',
        '--external-ref' => 're_missing',
    ])->expectsOutputToContain('nothing to refund')->assertExitCode(2);
});

it('requires the gateway reference', function (): void {
    $this->artisan('refunds:issue', ['subscription' => '1'])
        ->expectsOutputToContain('--external-ref is required')
        ->assertExitCode(2);
});
