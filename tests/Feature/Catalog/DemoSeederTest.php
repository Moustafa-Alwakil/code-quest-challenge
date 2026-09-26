<?php

declare(strict_types=1);

use App\Enums\AccrualPeriodStatus;
use App\Enums\LedgerAccountType;
use App\Models\AccrualPeriod;
use App\Models\Course;
use App\Models\EarningAllocation;
use App\Models\Engagement;
use App\Models\Enrolment;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PayoutItem;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
 * The demo dataset is what every scenario in the video is recorded against, so
 * a mistake in it is a mistake in five demonstrations at once.
 *
 * What these tests prove is not that the seeder runs — it is that each of the
 * five curated terms actually produces the decision it was built to show, once
 * `ledger:accrue` is run over it. A seeder whose S1 happened to divide evenly
 * would make the rounding demo show nothing, and nothing about it would look
 * broken.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00:00'));
    $this->seed(DemoSeeder::class);
});

afterEach(function (): void {
    assertLedgerBalanced();
});

it('seeds a catalog, an admin and thirteen terms whose ledger verifies', function (): void {
    expect(Instructor::query()->count())->toBe(5)
        ->and(Course::query()->count())->toBe(12)
        ->and(User::query()->where('is_admin', false)->count())->toBe(20)
        ->and(User::query()->where('is_admin', true)->count())->toBe(1)
        ->and(Enrolment::query()->count())->toBeGreaterThan(0)
        ->and(Subscription::query()->count())->toBe(13)
        /** One payment per term, through the real entry point. */
        ->and(Payment::query()->count())->toBe(Subscription::query()->count())
        ->and(AccrualPeriod::query()->count())->toBeGreaterThan(0)
        ->and(Engagement::query()->count())->toBeGreaterThan(0);

    /** Every term is born with its liability on the ledger and nothing earned. */
    expect(LedgerEntry::query()->count())->toBe(Subscription::query()->count() * 2)
        ->and(InstructorBalance::query()->count())->toBe(0)
        ->and(ledgerIsClean())->toBeTrue();
});

it('gives the admin a login the README can name', function (): void {
    $admin = User::query()->where('email', DemoSeeder::ADMIN_EMAIL)->firstOrFail();

    expect($admin->is_admin)->toBeTrue()
        ->and(Hash::check(DemoSeeder::ADMIN_PASSWORD, $admin->password))->toBeTrue()
        ->and($admin->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue();
});

it('only ever credits instructors the student is enrolled with', function (): void {
    /**
     * F02's coherence rule: nobody watches a course they are not enrolled in.
     * Engagement that broke it would still balance, and would still be a lie.
     */
    $offenders = Engagement::query()
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')
                ->from('enrolments')
                ->join('courses', 'courses.id', '=', 'enrolments.course_id')
                ->join('subscriptions', 'subscriptions.id', '=', 'subscription_period_engagement.subscription_id')
                ->whereColumn('enrolments.user_id', 'subscriptions.user_id')
                ->whereColumn('courses.instructor_id', 'subscription_period_engagement.instructor_id');
        })
        ->count();

    expect($offenders)->toBe(0);
});

it('writes nothing new when it is run a second time', function (): void {
    $before = seededFingerprint();

    $this->seed(DemoSeeder::class);

    /**
     * Every write the seeder makes is an `updateOrCreate`, an `insertOrIgnore`
     * or a `SubscribeStudentAction` replay, so a second run is a no-op — which
     * is what makes `db:seed` safe to reach for twice on a demo machine.
     */
    expect(seededFingerprint())->toBe($before);
});

it('produces the same money from a clean database every time', function (): void {
    $before = seededFingerprint();

    wipeSeededTables();

    expect(Subscription::query()->count())->toBe(0);

    $this->seed(DemoSeeder::class);

    /**
     * The seed is fixed and every amount the scenarios depend on is written out
     * rather than generated, so a second run from clean is the same database.
     * Compared on the money-bearing columns: transaction uuids are new each
     * time by design, and nothing depends on them.
     */
    expect(seededFingerprint())->toBe($before);
});

describe('the five scenarios, once accrued', function (): void {
    beforeEach(function (): void {
        Artisan::call('ledger:accrue', ['--sync' => true]);
    });

    it('S1 splits a pool three ways with a remainder to place', function (): void {
        $period = AccrualPeriod::query()
            ->where('subscription_id', subscriptionFor('ch_demo_s1'))
            ->where('status', AccrualPeriodStatus::RECOGNIZED)
            ->orderBy('sequence')
            ->firstOrFail();

        $shares = EarningAllocation::query()
            ->where('accrual_period_id', $period->id)
            ->orderBy('instructor_id')
            ->pluck('amount_minor');

        expect($shares)->toHaveCount(3)
            /** The remainder exists — otherwise the rounding demo shows nothing. */
            ->and($shares->unique())->toHaveCount(2)
            ->and($shares->first())->toBe($shares->last() + 1)
            ->and($shares->sum())->toBe($period->pool_minor)
            ->and($period->platform_minor + $shares->sum())->toBe($period->gross_minor);
    });

    it('S2 gives a dormant term entirely to the platform', function (): void {
        $periods = AccrualPeriod::query()->where('subscription_id', subscriptionFor('ch_demo_s2'))->get();

        expect($periods)->toHaveCount(1)
            ->and($periods->first()->status)->toBe(AccrualPeriodStatus::RECOGNIZED)
            ->and($periods->first()->pool_minor)->toBe(0)
            ->and($periods->first()->platform_minor)->toBe($periods->first()->gross_minor)
            ->and(EarningAllocation::query()->where('accrual_period_id', $periods->first()->id)->count())->toBe(0);
    });

    it('S3 is left mid-term, with unearned periods a refund can return', function (): void {
        $subscriptionId = subscriptionFor('ch_demo_s3');

        $scheduled = AccrualPeriod::query()
            ->where('subscription_id', $subscriptionId)
            ->where('status', AccrualPeriodStatus::SCHEDULED)
            ->count();

        expect($scheduled)->toBeGreaterThan(0)
            ->and(AccrualPeriod::query()->where('subscription_id', $subscriptionId)
                ->where('status', AccrualPeriodStatus::RECOGNIZED)->count())->toBeGreaterThan(0);
    });

    it('S4 hands its whole pool to one instructor', function (): void {
        $periodIds = AccrualPeriod::query()
            ->where('subscription_id', subscriptionFor('ch_demo_s4'))
            ->where('status', AccrualPeriodStatus::RECOGNIZED)
            ->pluck('id');

        $allocations = EarningAllocation::query()->whereIn('accrual_period_id', $periodIds)->get();

        expect($allocations->pluck('instructor_id')->unique())->toHaveCount(1)
            ->and($allocations->count())->toBe($periodIds->count());
    });

    it('S5 leaves one instructor below the payout minimum, carried forward', function (): void {
        $minimum = config('revenue.minimum_payout_minor');

        $smallest = InstructorBalance::query()->orderBy('earned_minor')->firstOrFail();

        expect($smallest->earned_minor)->toBeGreaterThan(0)
            ->and($smallest->earned_minor)->toBeLessThan($minimum);

        Artisan::call('payouts:run', ['--run-key' => 'payout:demo']);

        /** Skipped, and the balance is still theirs (D-7). */
        expect(PayoutItem::query()->where('instructor_id', $smallest->instructor_id)->count())->toBe(0)
            ->and(InstructorBalance::query()->findOrFail($smallest->instructor_id)->available_minor)
            ->toBe($smallest->earned_minor);
    });
});

/**
 * The seeded state, on the columns that carry money or meaning.
 *
 * Keyed by natural keys — a payment's `external_ref`, an instructor's email —
 * rather than by row ids. Ids are allocated by the database and are higher on a
 * re-seed whatever the seeder does; comparing them would make the determinism
 * test fail for the one reason that is not about the money. Timestamps and
 * transaction uuids are excluded for the same reason.
 *
 * @return array<string, mixed>
 */
function seededFingerprint(): array
{
    $refs = Payment::query()->pluck('external_ref', 'subscription_id');
    $instructors = Instructor::query()->pluck('email', 'id');

    $subscriptionRef = fn (int $id): string => $refs[$id] ?? "unknown:{$id}";

    return [
        'instructors' => Instructor::query()->orderBy('email')->pluck('name', 'email')->all(),
        'courses' => Course::query()->orderBy('slug')->pluck('title', 'slug')->all(),
        'students' => User::query()->orderBy('email')->pluck('name', 'email')->all(),

        'subscriptions' => Subscription::query()->orderBy('id')->get()
            ->map(fn (Subscription $s): array => [
                'ref' => $subscriptionRef($s->id),
                'price_minor' => $s->price_minor,
                'currency' => $s->currency,
                'term_start' => $s->term_start->toDateString(),
                'term_end' => $s->term_end->toDateString(),
                'term_days' => $s->term_days,
                'status' => $s->status->value,
            ])
            ->sortBy('ref')->values()->all(),

        'payments' => Payment::query()->orderBy('external_ref')->pluck('amount_minor', 'external_ref')->all(),

        'periods' => AccrualPeriod::query()->orderBy('subscription_id')->orderBy('sequence')->get()
            ->map(fn (AccrualPeriod $p): array => [
                'ref' => $subscriptionRef($p->subscription_id),
                'sequence' => $p->sequence,
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'days' => $p->days,
                'gross_minor' => $p->gross_minor,
                'status' => $p->status->value,
            ])
            ->sortBy(fn (array $row): string => $row['ref'].':'.$row['sequence'])->values()->all(),

        'engagement' => Engagement::query()->get()
            ->map(fn (Engagement $e): array => [
                'ref' => $subscriptionRef($e->subscription_id),
                'period_start' => $e->period_start->toDateString(),
                'instructor' => $instructors[$e->instructor_id] ?? 'unknown',
                'units' => $e->units,
            ])
            ->sortBy(fn (array $row): string => $row['ref'].':'.$row['period_start'].':'.$row['instructor'])
            ->values()->all(),

        'ledger' => LedgerEntry::query()->orderBy('id')->get()
            ->map(fn (LedgerEntry $entry): array => [
                'account_type' => $entry->account_type->value,
                /** Deferred revenue is keyed per subscription (R5), so name the term. */
                'account' => $entry->account_type === LedgerAccountType::DEFERRED_REVENUE
                    ? $subscriptionRef($entry->account_id)
                    : (string) $entry->account_id,
                'amount_minor' => $entry->amount_minor,
                'entry_type' => $entry->entry_type->value,
                'reference_type' => $entry->reference_type,
            ])
            ->sortBy(fn (array $row): string => $row['entry_type'].':'.$row['account_type'].':'.$row['account'])
            ->values()->all(),
    ];
}

/**
 * Empties every table the seeder writes, so the next run starts from clean.
 *
 * Inside the test's transaction, so it rolls back with everything else.
 */
function wipeSeededTables(): void
{
    Schema::disableForeignKeyConstraints();

    foreach ([
        'ledger_entries', 'subscription_period_engagement', 'accrual_periods',
        'payments', 'subscriptions', 'enrolments', 'courses', 'instructors',
        'users', 'plans',
    ] as $table) {
        DB::table($table)->delete();
    }

    Schema::enableForeignKeyConstraints();
}

function subscriptionFor(string $externalRef): int
{
    return Payment::query()->where('external_ref', $externalRef)->firstOrFail()->subscription_id;
}
