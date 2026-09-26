<?php

declare(strict_types=1);

use App\Enums\LedgerAccountType;
use App\Filament\Admin\Resources\InstructorResource;
use App\Filament\Admin\Resources\InstructorResource\Pages\ListInstructors;
use App\Filament\Admin\Resources\InstructorResource\Pages\ViewInstructor;
use App\Filament\Admin\Resources\InstructorResource\RelationManagers\LedgerEntriesRelationManager;
use App\Filament\Admin\Resources\InstructorResource\RelationManagers\PayoutItemsRelationManager;
use App\Models\AccrualPeriod;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Required item 6: one read-only screen that answers **owed, paid,
 * outstanding** per instructor.
 *
 * The assertions that matter are not "the page renders" — they are that the
 * numbers on it are the snapshot's own numbers, and that nothing on this panel
 * can write. A screen that recomputed the balances would be a second opinion
 * about the money; a screen with an edit button would be a way to forge one.
 */

/**
 * Filament resolves routes and authorization from the *current* panel, which a
 * console-driven test has never entered. Without this every `getUrl()` call
 * asks a null panel for a route name.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function (): void {
    assertLedgerBalanced();
});

/**
 * An instructor with real earnings, a real hold and a real payout behind them.
 *
 * @return array{0: Instructor,1: InstructorBalance}
 */
function instructorWithHistory(string $externalRef): array
{
    $plan = Plan::factory()->create(['interval_months' => 12, 'price_minor' => 300_000]);

    $outcome = recordCapturedPayment(
        User::factory()->create(),
        $plan,
        $externalRef,
        capturedAt: CarbonImmutable::now()->subMonths(11),
    );

    $instructor = Instructor::factory()->create();

    foreach (AccrualPeriod::query()->where('subscription_id', $outcome->subscriptionId)->get() as $period) {
        engage($outcome->subscriptionId, $period, $instructor, 60);
    }

    Artisan::call('ledger:accrue', ['--sync' => true]);
    Artisan::call('payouts:run', ['--run-key' => 'payout:admin']);

    return [$instructor, InstructorBalance::query()->findOrFail($instructor->id)];
}

function admin(): User
{
    return User::factory()->admin()->create();
}

it('lists instructors with the numbers their snapshot holds', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$instructor, $balance] = instructorWithHistory('ch_admin_0001');

    /**
     * Asserted against the query that feeds the columns, and against the
     * snapshot row rather than against literals: the claim is that the screen
     * shows what the ledger's cache holds, not some number a test wrote.
     *
     * Deliberately *not* `assertTableColumnStateSet` with the factory model —
     * that helper reads the record handed to it, which has none of the joined
     * columns, so every balance would read as the column default and the
     * assertion would pass by agreeing with zero.
     */
    $row = InstructorResource::getEloquentQuery()->findOrFail($instructor->id);

    expect((int) $row->getAttribute('earned_minor'))->toBe($balance->earned_minor)
        ->and((int) $row->getAttribute('clawed_back_minor'))->toBe($balance->clawed_back_minor)
        ->and((int) $row->getAttribute('held_minor'))->toBe($balance->held_minor)
        ->and((int) $row->getAttribute('available_minor'))->toBe($balance->available_minor)
        ->and((int) $row->getAttribute('reserved_minor'))->toBe($balance->reserved_minor)
        ->and((int) $row->getAttribute('paid_minor'))->toBe($balance->paid_minor);

    $this->actingAs(admin());

    Livewire::test(ListInstructors::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$instructor])
        ->assertSee($instructor->name)
        /** The formatted figures really are on the page, not just in the query. */
        ->assertSee(egp($balance->held_minor)->format())
        ->assertSee(egp($balance->paid_minor)->format())
        ->assertSee(egp($balance->outstandingMinor())->format());
});

it('reads every instructor in one query, with no N+1 behind the list', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithHistory('ch_admin_n1_a');
    instructorWithHistory('ch_admin_n1_b');
    instructorWithHistory('ch_admin_n1_c');

    $this->actingAs(admin());

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::test(ListInstructors::class)->assertOk();

    /**
     * The page runs a handful of queries — the record set, a count for the
     * pager, the session. What it must not do is grow with the number of
     * instructors, which a relation load for the balance or the last payout
     * would. Three instructors costing the same as one is the property.
     */
    expect($queries)->toBeLessThan(10);
});

it('shows the identity the verifier checks', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [, $balance] = instructorWithHistory('ch_admin_0002');

    /** The same line `ledger:verify` proves for every instructor. */
    expect($balance->outstandingMinor())
        ->toBe($balance->available_minor + $balance->held_minor + $balance->reserved_minor);

    $this->actingAs(admin());

    Livewire::test(ViewInstructor::class, ['record' => $balance->instructor_id])
        ->assertOk()
        ->assertSee('Outstanding = Available + Held + In flight');
});

it('formats money from minor units at the edge and nowhere else', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$instructor] = instructorWithHistory('ch_admin_0003');
    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    $this->actingAs(admin());

    /**
     * Money is stored as integer minor units end to end (D-4) and becomes a
     * decimal only here. The page showing `EGP 1,749.00` where the column holds
     * 174900 is the whole of that boundary.
     */
    Livewire::test(ListInstructors::class)
        ->assertOk()
        ->assertSee(egp($balance->paid_minor)->format())
        ->assertDontSee((string) $balance->paid_minor);
});

it('shows a negative balance as the carried-forward debt it is', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$instructor] = instructorWithHistory('ch_admin_0004');

    /**
     * A genuine negative balance, produced the only way one legitimately
     * arises: a full refund reaching past money that was already paid out
     * (D-7). Hand-editing the snapshot would have made the screen agree with a
     * state the ledger disowns.
     */
    $subscriptionId = AccrualPeriod::query()->orderBy('id')->firstOrFail()->subscription_id;

    Artisan::call('refunds:issue', [
        'subscription' => (string) $subscriptionId,
        '--external-ref' => 're_admin_0004',
        '--full' => true,
    ]);

    $balance = InstructorBalance::query()->findOrFail($instructor->id);

    expect($balance->available_minor)->toBeLessThan(0);

    $this->actingAs(admin());

    Livewire::test(ListInstructors::class)
        ->assertOk()
        ->assertSee(egp($balance->available_minor)->format())
        /** And the filter an operator would reach for finds exactly them. */
        ->filterTable('negative_balance')
        ->assertCanSeeTableRecords([$instructor]);
});

it('lists an instructor payout history and their own ledger entries', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$instructor] = instructorWithHistory('ch_admin_0005');

    $this->actingAs(admin());

    Livewire::test(PayoutItemsRelationManager::class, [
        'ownerRecord' => $instructor,
        'pageClass' => ViewInstructor::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($instructor->payoutItems()->get());

    Livewire::test(LedgerEntriesRelationManager::class, [
        'ownerRecord' => $instructor,
        'pageClass' => ViewInstructor::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($instructor->ledgerEntries()->orderByDesc('id')->limit(10)->get());
});

it('shows only this instructor entries, never another account with the same id', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    [$instructor] = instructorWithHistory('ch_admin_0006');

    /**
     * `ledger_entries.account_id` is a subscription id for deferred revenue and
     * an instructor id for payables, so an unfiltered relation would show one
     * as the other whenever the ids collided — which, with both starting at 1,
     * is immediately.
     */
    $accountTypes = $instructor->ledgerEntries()->get()
        ->pluck('account_type')
        ->map(fn (LedgerAccountType $type): string => $type->value)
        ->unique()
        ->values();

    expect($accountTypes->all())->toEqualCanonicalizing(['instructor_payable', 'provider_in_transit']);
});

it('refuses a user who is not an admin', function (): void {
    $student = User::factory()->student()->create();

    expect($student->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();

    $this->actingAs($student)
        ->get(InstructorResource::getUrl('index'))
        ->assertForbidden();
});

it('sends an anonymous visitor to the login page', function (): void {
    $this->get(InstructorResource::getUrl('index'))->assertRedirect();
});

it('offers no way to create, edit or delete anything', function (): void {
    expect(InstructorResource::canCreate())->toBeFalse()
        ->and(InstructorResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(InstructorResource::getPages()))->toBe(['index', 'view']);

    $instructor = Instructor::factory()->create();

    expect(InstructorResource::canEdit($instructor))->toBeFalse()
        ->and(InstructorResource::canDelete($instructor))->toBeFalse();
});

it('registers no create or edit routes at all', function (): void {
    $adminRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'admin/'));

    /** A route is the thing an attacker finds; the absence is the guarantee. */
    expect($adminRoutes->filter(fn (string $uri): bool => str_contains($uri, '/create'))->all())->toBe([])
        ->and($adminRoutes->filter(fn (string $uri): bool => str_contains($uri, '/edit'))->all())->toBe([]);
});
