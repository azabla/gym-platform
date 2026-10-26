<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\CheckIns\Pages;

use App\Filament\Staff\Resources\CheckIns\CheckInResource;
use Filament\Resources\Pages\ListRecords;

class ListCheckIns extends ListRecords
{
    protected static string $resource = CheckInResource::class;
}
