<?php

declare(strict_types=1);

use App\Exceptions\ImmutableLedgerException;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use Tests\Support\LedgerPostings;

/*
 * Never UPDATE or DELETE a ledger row: a correction is a new entry with its own
 * entry type. The model events are the application-level guard; production
 * hardening also revokes UPDATE and DELETE on the table from the application's
 * database user, so this holds for code that never loads the model.
 */

afterEach(function (): void {
    assertLedgerBalanced();
});

beforeEach(function (): void {
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

    $this->entry = LedgerEntry::query()->orderBy('id')->firstOrFail();
});

it('refuses to update a ledger entry', function (): void {
    expect(fn () => $this->entry->update(['amount_minor' => 1]))
        ->toThrow(ImmutableLedgerException::class, 'cannot be updated');

    expect(LedgerEntry::query()->findOrFail($this->entry->id)->amount_minor)->toBe(10_000);
});

it('refuses to delete a ledger entry', function (): void {
    expect(fn () => $this->entry->delete())
        ->toThrow(ImmutableLedgerException::class, 'cannot be deleted');

    expect(LedgerEntry::query()->count())->toBe(3);
});

it('names the entry it protected, so the failure points at a row', function (): void {
    expect(fn () => $this->entry->delete())
        ->toThrow(ImmutableLedgerException::class, "Ledger entry #{$this->entry->id}");
});

it('has no updated_at to rewrite', function (): void {
    expect(LedgerEntry::UPDATED_AT)->toBeNull()
        ->and($this->entry->getAttributes())->not->toHaveKey('updated_at')
        ->and(Schema::hasColumn('ledger_entries', 'updated_at'))->toBeFalse();
});

it('refuses a mass update through the query builder path the model guards', function (): void {
    expect(fn () => LedgerEntry::query()->get()->each->update(['amount_minor' => 0]))
        ->toThrow(ImmutableLedgerException::class);

    expect(LedgerEntry::query()->sum('amount_minor'))->toBe(0)
        ->and(LedgerEntry::query()->where('amount_minor', 10_000)->count())->toBe(1);
});
