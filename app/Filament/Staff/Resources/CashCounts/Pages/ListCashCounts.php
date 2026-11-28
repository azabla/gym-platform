<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\CashCounts\Pages;

use App\Filament\Staff\Resources\CashCounts\CashCountResource;
use Filament\Resources\Pages\ListRecords;

class ListCashCounts extends ListRecords
{
    protected static string $resource = CashCountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CashCountResource::closeAction(),
        ];
    }
}
