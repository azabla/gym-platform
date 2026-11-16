<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\DiscountGroups\Pages;

use App\Domains\Memberships\Models\DiscountGroup;
use App\Filament\Staff\Resources\DiscountGroups\DiscountGroupResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Change a discount group, archive it or restore it. Memberships already
 * sold at its price keep it.
 */
class EditDiscountGroup extends EditRecord
{
    protected static string $resource = DiscountGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DiscountGroupResource::archiveAction(),
            DiscountGroupResource::restoreAction(),
        ];
    }

    /**
     * @param  DiscountGroup  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): DiscountGroup
    {
        return DiscountGroupResource::saveGroup($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
