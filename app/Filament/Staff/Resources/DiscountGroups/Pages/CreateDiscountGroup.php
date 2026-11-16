<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\DiscountGroups\Pages;

use App\Domains\Memberships\Models\DiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\DiscountGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDiscountGroup extends CreateRecord
{
    protected static string $resource = DiscountGroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): DiscountGroup
    {
        return DiscountGroupResource::saveGroup(new DiscountGroup, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
