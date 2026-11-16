<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Branches\Pages;

use App\Filament\Staff\Resources\Branches\BranchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add branch'),
        ];
    }
}
