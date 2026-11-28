<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PlatformPlans\Pages;

use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Filament\Admin\Resources\PlatformPlans\PlatformPlanResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlatformPlan extends CreateRecord
{
    protected static string $resource = PlatformPlanResource::class;

    protected static ?string $title = 'New plan';

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return PlatformPlan::query()->create(PlatformPlanResource::fieldsFrom($data));
    }

    protected function getRedirectUrl(): string
    {
        return PlatformPlanResource::getUrl('index');
    }
}
