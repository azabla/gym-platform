<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\DiscountGroups\Pages;

use App\Filament\Staff\Resources\DiscountGroups\DiscountGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiscountGroups extends ListRecords
{
    protected static string $resource = DiscountGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add discount group'),
        ];
    }
}
