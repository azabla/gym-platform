<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PromoCodes\Pages;

use App\Domains\Memberships\Models\PromoCode;
use App\Filament\Staff\Resources\PromoCodes\PromoCodeResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Change a promo code, archive it or restore it. Memberships already sold
 * with it keep their price.
 */
class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PromoCodeResource::archiveAction(),
            PromoCodeResource::restoreAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $promoCode = $this->getRecord();

        assert($promoCode instanceof PromoCode);

        return [
            ...$data,
            'starts_on' => $promoCode->starts_on?->toDateString(),
            'ends_on' => $promoCode->ends_on?->toDateString(),
            'plan_ids' => $promoCode->limitedToPlanIds() ?? [],
        ];
    }

    /**
     * @param  PromoCode  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): PromoCode
    {
        return PromoCodeResource::saveCode($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
