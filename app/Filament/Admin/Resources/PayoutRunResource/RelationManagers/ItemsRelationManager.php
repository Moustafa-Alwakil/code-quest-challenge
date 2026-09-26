<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayoutRunResource\RelationManagers;

use App\Enums\PayoutItemStatus;
use App\Filament\Admin\Support\Displays;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The items of one run, with the attempt count that makes a retry visible
 * (F10).
 *
 * `attempts` above one against a `succeeded` item is the whole story of F07 and
 * F08 in a single cell: we asked more than once and the money still moved once.
 */
final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('instructor.name')
                    ->label('Instructor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => Displays::money($state)),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PayoutItemStatus $state): string => Displays::status($state->value))
                    ->color(fn (PayoutItemStatus $state): string => match ($state) {
                        PayoutItemStatus::SUCCEEDED => 'success',
                        PayoutItemStatus::FAILED, PayoutItemStatus::NEEDS_REVIEW => 'danger',
                        PayoutItemStatus::UNKNOWN => 'warning',
                        PayoutItemStatus::RESERVED, PayoutItemStatus::SUBMITTED => 'gray',
                    }),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('Attempts')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('provider_reference')
                    ->label('Provider ref')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('next_check_at')
                    ->label('Next check')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('payout_items.id')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(PayoutItemStatus::class),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canEdit(Model $record): bool
    {
        return false;
    }

    public function canDelete(Model $record): bool
    {
        return false;
    }
}
