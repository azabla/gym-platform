<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms\Pages;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Language\Language;
use App\Filament\Admin\Resources\Gyms\GymResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Changing a gym's name, time zone and members' message language. The slug
 * stays as it was opened (ADR 0042).
 */
class EditGym extends EditRecord
{
    protected static string $resource = GymResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        $record->changeDetails((string) $data['name'], (string) $data['timezone'], Language::from((string) $data['message_language']));

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return GymResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
