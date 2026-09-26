<?php

declare(strict_types=1);

use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Filament\Admin\Resources\PayoutRunResource;
use App\Filament\Admin\Resources\PayoutRunResource\Pages\ListPayoutRuns;
use App\Filament\Admin\Resources\PayoutRunResource\Pages\ViewPayoutRun;
use App\Filament\Admin\Resources\PayoutRunResource\RelationManagers\ItemsRelationManager;
use App\Models\PayoutItem;
use App\Models\PayoutRun;
use App\Models\User;
use App\Services\ScriptedMockProvider;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/*
 * The screen scenarios 1, 2 and 4 are watched on.
 *
 * What it has to make legible is the difference between a run that finished and
 * one that sent everything and is still waiting to hear — `completed` versus
 * `completed_with_pending` (D-8, R33). A panel that showed both as "done" would
 * make the timeout demo impossible to narrate.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function (): void {
    assertLedgerBalanced();
});

it('lists runs with their totals and per-status item counts', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithHistory('ch_run_admin_0001');
    instructorWithHistory('ch_run_admin_0002');

    $run = PayoutRun::query()->firstOrFail();

    $this->actingAs(User::factory()->admin()->create());

    /** Counts come from the list query, so the page does not grow a query per run. */
    $row = PayoutRunResource::getEloquentQuery()->findOrFail($run->id);

    expect((int) $row->getAttribute('items_succeeded'))
        ->toBe(PayoutItem::query()->where('status', PayoutItemStatus::SUCCEEDED)->count())
        ->and((int) $row->getAttribute('items_unknown'))->toBe(0);

    Livewire::test(ListPayoutRuns::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$run])
        ->assertSee($run->run_key)
        ->assertSee(egp($run->total_minor)->format());
});

it('tells a finished run apart from one still waiting on the provider', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    /** A payout whose transfer succeeded and whose answer was lost. */
    provider()->script([ScriptedMockProvider::OUTCOME_TIMEOUT_AFTER_SUCCESS]);

    instructorWithHistory('ch_run_admin_0003');

    $run = PayoutRun::query()->firstOrFail();

    expect($run->status)->toBe(PayoutRunStatus::COMPLETED_WITH_PENDING)
        ->and(PayoutItem::query()->firstOrFail()->status)->toBe(PayoutItemStatus::UNKNOWN);

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListPayoutRuns::class)
        ->assertOk()
        ->assertSee('Completed with pending');

    /** Reconcile in the terminal, refresh the page: amber becomes green. */
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:05:00'));
    Artisan::call('payouts:reconcile', ['--sync' => true]);

    expect(PayoutRun::query()->firstOrFail()->status)->toBe(PayoutRunStatus::COMPLETED)
        ->and(PayoutItem::query()->firstOrFail()->status)->toBe(PayoutItemStatus::SUCCEEDED);

    Livewire::test(ListPayoutRuns::class)
        ->assertOk()
        ->assertDontSee('Completed with pending');
});

it('lists a run items with the attempt count that makes a retry visible', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));

    instructorWithHistory('ch_run_admin_0004');

    $run = PayoutRun::query()->firstOrFail();

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewPayoutRun::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords($run->items()->get());
});

it('refuses a user who is not an admin', function (): void {
    $this->actingAs(User::factory()->student()->create())
        ->get(PayoutRunResource::getUrl('index'))
        ->assertForbidden();
});

it('offers no way to create, edit or delete a run', function (): void {
    $run = PayoutRun::factory()->create();

    expect(PayoutRunResource::canCreate())->toBeFalse()
        ->and(PayoutRunResource::canEdit($run))->toBeFalse()
        ->and(PayoutRunResource::canDelete($run))->toBeFalse()
        ->and(PayoutRunResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(PayoutRunResource::getPages()))->toBe(['index', 'view']);
});
