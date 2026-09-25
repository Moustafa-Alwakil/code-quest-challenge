<?php

declare(strict_types=1);

use App\Models\Instructor;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LedgerPostings;

/*
 * How strong is "the ledger is append-only", really?
 *
 * LedgerImmutabilityTest proves the model events fire. Model events only fire
 * for writes that go *through a model instance*, and four common ways to write
 * to a table do not. These tests pin what actually happens on each of those
 * paths, so nobody reads the green suite as a guarantee it does not give.
 *
 * F03's own Notes name the real fix and mark it production hardening rather
 * than built: revoke UPDATE and DELETE on `ledger_entries` from the
 * application's database user, so MySQL enforces this and not just PHP. Until
 * then `ledger:verify` is the compensating control, and these tests check it
 * catches each bypass.
 *
 * assertLedgerBalanced() is not registered here: half these tests corrupt the
 * ledger on purpose, and each asserts the verifier's verdict itself.
 */

beforeEach(function (): void {
    $this->instructor = Instructor::factory()->create();

    LedgerPostings::post(
        LedgerPostings::recognition(
            periodId: 1,
            subscriptionId: 1,
            instructorId: $this->instructor->id,
            grossMinor: 10_000,
            instructorMinor: 7_000,
        ),
        LedgerPostings::recognizedAndReleased($this->instructor->id, 7_000),
    );
});

it('does NOT stop a mass update through the Eloquent query builder', function (): void {
    $affected = LedgerEntry::query()->where('amount_minor', 10_000)->update(['amount_minor' => 1]);

    /**
     * No exception. `Builder::update()` compiles straight to SQL and never
     * instantiates a model, so the `updating` event has nothing to fire on.
     */
    expect($affected)->toBe(1)
        ->and(LedgerEntry::query()->where('amount_minor', 1)->count())->toBe(1);

    /** The compensating control does catch it. */
    expect(ledgerIsClean())->toBeFalse();

    $this->artisan('ledger:verify')->assertExitCode(1);
});

it('does NOT stop a mass delete through the Eloquent query builder', function (): void {
    $deleted = LedgerEntry::query()->where('account_id', $this->instructor->id)->delete();

    expect($deleted)->toBe(1)
        ->and(LedgerEntry::query()->count())->toBe(2)
        ->and(ledgerIsClean())->toBeFalse();
});

it('does NOT stop a write through the raw query builder', function (): void {
    DB::table('ledger_entries')->update(['amount_minor' => 0]);

    expect(LedgerEntry::query()->where('amount_minor', 0)->count())->toBe(3)
        ->and(ledgerIsClean())->toBeFalse();
});

it('does NOT stop the whole ledger being emptied', function (): void {
    /**
     * `LedgerEntry::query()->truncate()` is the same bypass and is *not*
     * blocked either; it is written as a mass delete here only because TRUNCATE
     * forces an implicit commit in MySQL and would break RefreshDatabase's
     * isolation for every test that follows.
     */
    DB::table('ledger_entries')->delete();

    expect(LedgerEntry::query()->count())->toBe(0);

    /**
     * The nastiest of the four: the ledger is empty, so checks 1 and 2 pass
     * trivially. Only the snapshot comparison notices that 7 000 EGP of
     * earnings no longer has anything behind it.
     */
    expect(ledgerIsClean())->toBeFalse();

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('earned_minor')
        ->assertExitCode(1);
});

it('does stop every path that loads a model first', function (): void {
    $entry = LedgerEntry::query()->orderBy('id')->firstOrFail();

    expect(fn () => $entry->update(['amount_minor' => 1]))
        ->toThrow(App\Exceptions\ImmutableLedgerException::class)
        ->and(fn () => $entry->delete())
        ->toThrow(App\Exceptions\ImmutableLedgerException::class)
        ->and(fn () => LedgerEntry::destroy($entry->id))
        ->toThrow(App\Exceptions\ImmutableLedgerException::class)
        ->and(fn () => LedgerEntry::query()->get()->each->delete())
        ->toThrow(App\Exceptions\ImmutableLedgerException::class);

    expect(LedgerEntry::query()->count())->toBe(3)
        ->and(ledgerIsClean())->toBeTrue();
});

it('has no updated_at column for anything to rewrite', function (): void {
    expect(Schema::hasColumn('ledger_entries', 'updated_at'))->toBeFalse()
        ->and(LedgerEntry::UPDATED_AT)->toBeNull();
});
