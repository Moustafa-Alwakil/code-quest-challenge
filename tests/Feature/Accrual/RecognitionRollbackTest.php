<?php

declare(strict_types=1);

use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\LedgerIntegrityException;
use App\Models\AccrualPeriod;
use App\Models\EarningAllocation;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The crash case: recognition fails after it has already written allocation
 * rows, and everything — the allocations, the posting and the status change —
 * has to come back out together.
 *
 * F05's test list suggests a test double throwing after step 5. This uses a
 * real failure instead: one leg of the posting is planted in advance, so
 * `insertOrIgnore` writes two of three legs and `LedgerService::post()` raises
 * a partial-duplicate. That exercises the same rollback through the code path
 * that would actually produce it, rather than through a seam built for the
 * test.
 *
 * What makes this worth proving is R3. There is no `recognizing` status, so the
 * rollback is what returns the period to `scheduled` and lets the next run pick
 * it up. If the CAS committed separately, this period would be stranded as
 * recognized with no money behind it.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

it('takes the allocations and the status back out when the posting fails', function (): void {
    $this->travelTo(CarbonImmutable::parse('2024-06-15 09:00:00'));

    $plan = Plan::factory()->create(['interval_months' => 1, 'price_minor' => 30_000]);
    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        'ch_rollback_0001',
        capturedAt: CarbonImmutable::now(),
    );

    $period = AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->firstOrFail();
    $instructor = Instructor::factory()->create();

    engage($outcome->subscriptionId, $period, $instructor, 60);

    /**
     * One leg of the posting this recognition is about to write, already on
     * file. The unique key swallows it, the other two go in, and the count
     * mismatch is what `post()` refuses to tolerate.
     */
    DB::table('ledger_entries')->insert([
        'transaction_uuid' => (string) Str::uuid(),
        'account_type' => LedgerAccountType::PLATFORM_REVENUE->value,
        'account_id' => 0,
        'amount_minor' => -9_000,
        'currency' => 'EGP',
        'entry_type' => LedgerEntryType::PERIOD_RECOGNIZED->value,
        'reference_type' => 'accrual_period',
        'reference_id' => $period->id,
        'created_at' => CarbonImmutable::now(),
    ]);

    expect(fn () => recognizeFirstPeriodOf($outcome->subscriptionId))
        ->toThrow(LedgerIntegrityException::class);

    /** Nothing of the recognition survived — not even the status it set first. */
    expect($period->refresh()->status)->toBe(AccrualPeriodStatus::SCHEDULED)
        ->and($period->recognized_at)->toBeNull()
        ->and($period->pool_minor)->toBeNull()
        ->and(EarningAllocation::query()->count())->toBe(0)
        ->and(InstructorBalance::query()->count())->toBe(0);

    /** Clear the planted leg; the ledger is the thing under repair, not the code. */
    DB::table('ledger_entries')
        ->where('entry_type', LedgerEntryType::PERIOD_RECOGNIZED->value)
        ->where('reference_id', $period->id)
        ->delete();

    /** The next run finds the period exactly as the failed one found it. */
    $recognized = recognizeFirstPeriodOf($outcome->subscriptionId);

    expect($recognized->status)->toBe(AccrualPeriodStatus::RECOGNIZED)
        ->and($recognized->pool_minor)->toBe(21_000)
        ->and(EarningAllocation::query()->count())->toBe(1)
        ->and(InstructorBalance::query()->findOrFail($instructor->id)->held_minor)->toBe(21_000);
});
