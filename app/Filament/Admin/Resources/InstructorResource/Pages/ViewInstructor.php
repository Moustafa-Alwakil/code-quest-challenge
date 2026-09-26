<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstructorResource\Pages;

use App\Filament\Admin\Resources\InstructorResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One instructor's balance, payout history and ledger entries (F10).
 *
 * The page every failure demo is watched on: an item sits amber in `unknown`,
 * `payouts:reconcile` runs in the terminal beside it, and it turns green — with
 * the attempts list showing two interactions and one transfer.
 */
final class ViewInstructor extends ViewRecord
{
    protected static string $resource = InstructorResource::class;

    /**
     * No edit action. The panel observes the ledger; it never writes to it.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
