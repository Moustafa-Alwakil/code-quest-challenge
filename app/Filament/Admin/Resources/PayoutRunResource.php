<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\PayoutRunStatus;
use App\Filament\Admin\Resources\PayoutRunResource\Pages;
use App\Filament\Admin\Resources\PayoutRunResource\RelationManagers;
use App\Filament\Admin\Support\Displays;
use App\Models\PayoutRun;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Payout runs, and what became of each one's items (F10).
 *
 * What makes it worth a screen is the status: `completed_with_pending` is a run
 * that sent everything and is still waiting to hear, which is the honest answer
 * D-8 insists on and the thing scenarios 1, 2 and 4 are demonstrating. A run
 * that merely said "completed" would make those demos unreadable.
 *
 * Read-only like everything else here. A run is started by `payouts:run`, never
 * by a button.
 */
final class PayoutRunResource extends Resource
{
    /**
     * The item statuses shown as counts on the list, and how each one reads.
     *
     * `unknown` is amber rather than red on purpose: money the provider has not
     * accounted for is not money that failed (D-8).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const COUNTED_STATUSES = [
        'succeeded' => ['Succeeded', 'success'],
        'failed' => ['Failed', 'danger'],
        'unknown' => ['Unknown', 'warning'],
    ];

    protected static ?string $model = PayoutRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $recordTitleAttribute = 'run_key';

    protected static ?int $navigationSort = 2;

    /**
     * One query for the page: the runs, plus a constrained count per status.
     *
     * `withCount` compiles to correlated sub-selects, so a page of twenty runs
     * costs one round trip rather than sixty.
     *
     * @return Builder<PayoutRun>
     */
    public static function getEloquentQuery(): Builder
    {
        $counts = [];

        foreach (array_keys(self::COUNTED_STATUSES) as $status) {
            $counts["items as items_{$status}"] = static fn (Builder $items): Builder => $items->where('status', $status);
        }

        return parent::getEloquentQuery()->withCount($counts);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('run_key')
                    ->label('Run key')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    /** Read as prose, but never given a label the enum does not carry. */
                    ->formatStateUsing(fn (PayoutRunStatus $state): string => Displays::status($state->value))
                    ->color(fn (PayoutRunStatus $state): string => match ($state) {
                        PayoutRunStatus::COMPLETED => 'success',
                        /** Not a failure: everything was sent, and the provider owes an answer. */
                        PayoutRunStatus::COMPLETED_WITH_PENDING => 'warning',
                        PayoutRunStatus::DISPATCHED, PayoutRunStatus::OPEN => 'gray',
                    }),

                Tables\Columns\TextColumn::make('item_count')
                    ->label('Items')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_minor')
                    ->label('Reserved')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Displays::money($state)),

                /** Counted in the list query itself, so a page of runs is one round trip. */
                ...self::statusCounts(),

                Tables\Columns\TextColumn::make('scheduled_for')
                    ->label('For')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('payout_runs.id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(PayoutRunStatus::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayoutRuns::route('/'),
            'view' => Pages\ViewPayoutRun::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * The three counts worth seeing at a glance: what landed, what did not, and
     * what nobody knows yet.
     *
     * @return list<Tables\Columns\TextColumn>
     */
    private static function statusCounts(): array
    {
        $counts = [];

        foreach (self::COUNTED_STATUSES as $status => [$label, $color]) {
            /** An explicit `as` alias is used verbatim: no `_count` suffix is appended. */
            $counts[] = Tables\Columns\TextColumn::make("items_{$status}")
                ->label($label)
                ->alignCenter()
                ->badge()
                ->color($color)
                ->default(0);
        }

        return $counts;
    }
}
