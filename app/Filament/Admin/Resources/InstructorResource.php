<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\InstructorResource\Pages;
use App\Filament\Admin\Resources\InstructorResource\RelationManagers;
use App\Filament\Admin\Support\Displays;
use App\Models\Instructor;
use App\Models\PayoutItem;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The screen that answers the brief's three questions — **owed, paid,
 * outstanding** — per instructor (F10, required item 6).
 *
 * Read-only, and not merely by convention: create, edit and delete are all
 * refused, so there is no route through this panel that can move a piastre.
 * Money moves through Actions with a DTO contract, or it does not move.
 *
 * Every number here comes from the `instructor_balances` snapshot. The screen
 * never sums `ledger_entries` at request time — at tens of millions of rows
 * that is a slow page, and more importantly it would make the admin a *second*
 * opinion about the money rather than a window onto the first. `ledger:verify`
 * is what proves the snapshot is telling the truth; this just displays it.
 */
final class InstructorResource extends Resource
{
    protected static ?string $model = Instructor::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    /**
     * One query: instructors joined to their snapshot, plus a sub-select for
     * the most recent payout.
     *
     * The join rather than an eager load, because the table sorts and filters
     * on balance columns and a relation load cannot be ordered by. The
     * sub-select rather than a relation, because "last payout" is one value per
     * row and loading the whole payout history to find it is the N+1 this note
     * exists to prevent.
     */
    /**
     * @return Builder<Instructor>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->leftJoin('instructor_balances', 'instructor_balances.instructor_id', '=', 'instructors.id')
            ->select([
                'instructors.*',
                'instructor_balances.earned_minor',
                'instructor_balances.clawed_back_minor',
                'instructor_balances.held_minor',
                'instructor_balances.available_minor',
                'instructor_balances.reserved_minor',
                'instructor_balances.paid_minor',
            ])
            ->addSelect([
                'last_payout_at' => PayoutItem::query()
                    ->select('created_at')
                    ->whereColumn('payout_items.instructor_id', 'instructors.id')
                    ->orderByDesc('id')
                    ->limit(1),
                'last_payout_status' => PayoutItem::query()
                    ->select('status')
                    ->whereColumn('payout_items.instructor_id', 'instructors.id')
                    ->orderByDesc('id')
                    ->limit(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                self::money('available_minor', 'Available')
                    /** Negative means the instructor owes the platform after a clawback (D-7). */
                    ->color(fn ($state): string => Displays::minor($state) < 0 ? 'danger' : 'gray')
                    ->tooltip(fn ($state): ?string => Displays::minor($state) < 0
                        ? 'Carried forward, netted against future earnings'
                        : null),

                self::money('held_minor', 'Held'),

                self::money('reserved_minor', 'In flight'),

                self::money('earned_minor', 'Earned (lifetime)')
                    ->toggleable(isToggledHiddenByDefault: true),

                self::money('clawed_back_minor', 'Clawed back')
                    ->toggleable(isToggledHiddenByDefault: true),

                self::money('paid_minor', 'Paid (lifetime)'),

                /** The brief's third question, spelled out: earned − clawed back − paid. */
                Tables\Columns\TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->alignEnd()
                    ->state(fn (Instructor $record): int => self::outstandingOf($record))
                    ->formatStateUsing(fn ($state): string => self::format($state))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('last_payout_at')
                    ->label('Last payout')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('never')
                    ->description(fn (Instructor $record): ?string => self::lastPayoutStatus($record)),
            ])
            ->defaultSort('instructors.id')
            ->filters([
                Tables\Filters\Filter::make('negative_balance')
                    ->label('Carrying a negative balance')
                    ->query(fn (Builder $query): Builder => $query->where('instructor_balances.available_minor', '<', 0)),

                Tables\Filters\Filter::make('needs_review')
                    ->label('Has a payout needing review')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'payoutItems',
                        fn (Builder $items): Builder => $items->where('status', 'needs_review'),
                    )),

                Tables\Filters\Filter::make('outstanding')
                    ->label('Still owed money')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        'coalesce(instructor_balances.earned_minor, 0) - coalesce(instructor_balances.clawed_back_minor, 0) - coalesce(instructor_balances.paid_minor, 0) > 0'
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            /** No bulk actions: there is nothing here a bulk action could safely do. */
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Balance')
                /** The screen explains its own arithmetic, so nobody has to trust it. */
                ->description('Outstanding = Available + Held + In flight')
                ->columns(4)
                ->schema([
                    TextEntry::make('available_minor')
                        ->label('Available')
                        ->state(fn (Instructor $record): string => self::format(self::balanceField($record, 'available_minor')))
                        ->color(fn (Instructor $record): string => self::balanceField($record, 'available_minor') < 0 ? 'danger' : 'gray'),

                    TextEntry::make('held_minor')
                        ->label('Held')
                        ->state(fn (Instructor $record): string => self::format(self::balanceField($record, 'held_minor'))),

                    TextEntry::make('reserved_minor')
                        ->label('In flight')
                        ->state(fn (Instructor $record): string => self::format(self::balanceField($record, 'reserved_minor'))),

                    TextEntry::make('outstanding')
                        ->label('Outstanding')
                        ->weight('bold')
                        ->state(fn (Instructor $record): string => self::format(self::outstandingOf($record))),
                ]),

            Section::make('Lifetime')
                ->columns(3)
                ->schema([
                    TextEntry::make('earned_minor')
                        ->label('Earned')
                        ->state(fn (Instructor $record): string => self::format(self::balanceField($record, 'earned_minor'))),

                    TextEntry::make('clawed_back_minor')
                        ->label('Clawed back')
                        ->state(fn (Instructor $record): string => self::format(self::balanceField($record, 'clawed_back_minor'))),

                    TextEntry::make('paid_minor')
                        ->label('Paid')
                        ->state(fn (Instructor $record): string => self::format(self::balanceField($record, 'paid_minor'))),
                ]),
        ]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\PayoutItemsRelationManager::class,
            RelationManagers\LedgerEntriesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * A money column, formatted from minor units at the very edge and nowhere
     * else. The integer is the value; this is presentation (D-4).
     */
    private static function money(string $name, string $label): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->sortable()
            ->default(0)
            ->formatStateUsing(fn ($state): string => self::format($state));
    }

    private static function format(mixed $minor): string
    {
        return Displays::money($minor);
    }

    private static function balanceField(Instructor $record, string $field): int
    {
        return Displays::minor($record->getAttribute($field));
    }

    /**
     * `earned − clawed_back − paid`, the same derivation `ledger:verify` checks
     * against `available + held + reserved` for every instructor.
     */
    private static function outstandingOf(Instructor $record): int
    {
        return self::balanceField($record, 'earned_minor')
            - self::balanceField($record, 'clawed_back_minor')
            - self::balanceField($record, 'paid_minor');
    }

    private static function lastPayoutStatus(Instructor $record): ?string
    {
        return Displays::text($record->getAttribute('last_payout_status'));
    }
}
