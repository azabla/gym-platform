<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Members\Pages;

use App\Filament\Staff\Resources\Members\MemberResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;
}
