<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstructorResource\RelationManagers;

use App\Enums\PayoutItemStatus;
use App\Filament\Admin\Support\Displays;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What this instructor has been paid, and what happened when (F10).
 *
 * The status badge is the demo's whole vocabulary: amber `unknown` is money we
 * cannot account for yet, and watching it turn green after `payouts:reconcile`
 * is scenario 4. `needs_review` is outlined rather than filled, because it is
 * not a failure — it is a question for a person, with the money still frozen.
 */
final class PayoutItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'payoutItems';

    protected static ?string $title = 'Payout history';

    public function canCreate(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('idempotency_key')
            ->columns([
                Tables\Columns\TextColumn::make('payoutRun.run_key')
                    ->label('Run'),

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

                Tables\Columns\TextColumn::make('provider_reference')
                    ->label('Provider ref')
                    ->placeholder('—')
                    ->copyable(),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('Attempts')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('settled_at')
                    ->label('Settled')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('last_error')
                    ->label('Last error')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('payout_items.id', 'desc')
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

    public function canEdit(Model $record): bool
    {
        return false;
    }

    public function canDelete(Model $record): bool
    {
        return false;
    }
}
