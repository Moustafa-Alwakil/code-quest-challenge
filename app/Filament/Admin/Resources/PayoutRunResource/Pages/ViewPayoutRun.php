<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayoutRunResource\Pages;

use App\Filament\Admin\Resources\PayoutRunResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * One run and its items (F10). Scenario 1 on camera: run the command twice,
 * refresh this page, and there is still one item per instructor.
 */
final class ViewPayoutRun extends ViewRecord
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
