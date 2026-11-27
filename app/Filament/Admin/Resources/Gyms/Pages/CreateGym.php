<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Gyms\Pages;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Onboarding\NewGym;
use App\Filament\Admin\Resources\Gyms\GymResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Opening a gym, as gyms:create does: the gym, its first branch and its
 * owner, who gets an SMS to set their password (ADR 0042).
 */
class CreateGym extends CreateRecord
{
    protected static string $resource = GymResource::class;

    protected static ?string $title = 'New gym';

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var array{name: string, slug: string, timezone: string, message_language: string, branch: string, owner_name: string, owner_phone: string} $data */
        $owner = Organization::open(NewGym::fromValidated($data));

        return Organization::query()->findOrFail($owner->organization_id);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Gym opened. The owner has an SMS to set their password.';
    }

    protected function getRedirectUrl(): string
    {
        return GymResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
