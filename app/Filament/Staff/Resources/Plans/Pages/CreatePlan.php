<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Plans\Pages;

use App\Domains\Memberships\Models\Plan;
use App\Filament\Staff\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Plan
    {
        return PlanResource::savePlan(new Plan, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
