<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PromoCodes\Pages;

use App\Filament\Staff\Resources\PromoCodes\PromoCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromoCodes extends ListRecords
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add promo code'),
        ];
    }
}
