<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PromoCodes\Pages;

use App\Domains\Memberships\Models\PromoCode;
use App\Filament\Staff\Resources\PromoCodes\PromoCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromoCode extends CreateRecord
{
    protected static string $resource = PromoCodeResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): PromoCode
    {
        return PromoCodeResource::saveCode(new PromoCode, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
