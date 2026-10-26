<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Memberships\Pages;

use App\Filament\Staff\Resources\Memberships\MembershipResource;
use Filament\Resources\Pages\ListRecords;

class ListMemberships extends ListRecords
{
    protected static string $resource = MembershipResource::class;
}
