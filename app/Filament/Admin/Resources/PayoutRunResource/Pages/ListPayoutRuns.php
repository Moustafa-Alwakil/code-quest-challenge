<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayoutRunResource\Pages;

use App\Filament\Admin\Resources\PayoutRunResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Every payout run, newest first (F10).
 */
final class ListPayoutRuns extends ListRecords
{
    protected static string $resource = PayoutRunResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
