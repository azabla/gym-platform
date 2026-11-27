<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms\Pages;

use App\Filament\Admin\Resources\Gyms\GymResource;
use Filament\Resources\Pages\ListRecords;

class ListGyms extends ListRecords
{
    protected static string $resource = GymResource::class;
}
