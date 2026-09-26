<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstructorResource\RelationManagers;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Filament\Admin\Support\Displays;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * This instructor's own ledger entries — the source of truth the balance above
 * is a cache of (F10, D-9).
 *
 * **Simple pagination, deliberately.** Filament's default pager runs a
 * `COUNT(*)` to know how many pages there are, and at tens of millions of
 * entries that count is the slowest thing on the page. Next/previous needs no
 * total, and the index `(account_type, account_id, id)` serves the window
 * directly.
 *
 * Descending by id, because the interesting entry is almost always the most
 * recent one.
 */
final class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Ledger entries';

    public function canCreate(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Posted')
                    ->dateTime('Y-m-d H:i:s'),

                Tables\Columns\TextColumn::make('entry_type')
                    ->label('Why')
                    ->badge()
                    ->color(fn (LedgerEntryType $state): string => match ($state) {
                        LedgerEntryType::PERIOD_RECOGNIZED => 'success',
                        LedgerEntryType::REFUND_CLAWBACK, LedgerEntryType::PAYOUT_REVERSED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('account_type')
                    ->label('Account')
                    ->formatStateUsing(fn (LedgerAccountType $state): string => $state->value),

                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    /** Debit positive, credit negative — the sign is the direction (F03). */
                    ->color(fn ($state): string => Displays::minor($state) < 0 ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state): string => Displays::money($state)),

                Tables\Columns\TextColumn::make('reference_type')
                    ->label('Records')
                    ->description(fn (Model $record): string => (string) Displays::minor($record->getAttribute('reference_id'))),

                Tables\Columns\TextColumn::make('transaction_uuid')
                    ->label('Transaction')
                    ->limit(8)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('ledger_entries.id', 'desc')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function canEdit(Model $record): bool
    {
        return false;
    }

    public function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Simple pagination, deliberately.
     *
     * Filament's default pager runs a `COUNT(*)` to know how many pages there
     * are, and over tens of millions of ledger entries that count is by far the
     * slowest thing on the page. Next/previous needs no total, and the index
     * `(account_type, account_id, id)` serves the window directly.
     *
     * Overriding the paginator is the v3 way to say this; there is no table
     * builder method for it.
     *
     * @param  Builder<Model>        $query
     * @return Paginator<int, Model>
     */
    protected function paginateTableQuery(Builder $query): Paginator
    {
        $perPage = $this->getTableRecordsPerPage();

        return $query->simplePaginate(
            $perPage === 'all' ? $query->count() : (int) $perPage,
            pageName: $this->getTablePaginationPageName(),
        );
    }
}
