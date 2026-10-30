<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Staff\Pages;

use App\Filament\Staff\Resources\Staff\StaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add staff member'),
        ];
    }
}
