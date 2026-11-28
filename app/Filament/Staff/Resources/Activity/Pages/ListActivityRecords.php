<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Activity\Pages;

use App\Filament\Staff\Resources\Activity\ActivityRecordResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityRecords extends ListRecords
{
    protected static string $resource = ActivityRecordResource::class;
}
