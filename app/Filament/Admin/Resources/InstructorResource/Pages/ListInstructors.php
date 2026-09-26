<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstructorResource\Pages;

use App\Filament\Admin\Resources\InstructorResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Every instructor and what they are owed (F10).
 *
 * No header actions: there is nothing to create here, because an instructor
 * with no ledger history has nothing this screen exists to show.
 */
final class ListInstructors extends ListRecords
{
    protected static string $resource = InstructorResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
