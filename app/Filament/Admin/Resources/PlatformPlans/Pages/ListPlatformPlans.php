<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PlatformPlans\Pages;

use App\Filament\Admin\Resources\PlatformPlans\PlatformPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformPlans extends ListRecords
{
    protected static string $resource = PlatformPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New plan'),
        ];
    }
}
