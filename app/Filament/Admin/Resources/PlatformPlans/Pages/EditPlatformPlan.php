<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PlatformPlans\Pages;

use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Filament\Admin\Resources\PlatformPlans\PlatformPlanResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Changing a plan's name, prices or discounts. Each gym on it pays the new
 * prices from its next invoice (ADR 0045).
 */
class EditPlatformPlan extends EditRecord
{
    protected static string $resource = PlatformPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlatformPlanResource::retireAction(),
            PlatformPlanResource::offerAgainAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $plan = $this->getRecord();

        assert($plan instanceof PlatformPlan);

        return [
            ...$data,
            'monthly_price' => $plan->monthly_price->toDecimal(),
            'extra_branch_price' => $plan->extra_branch_price->toDecimal(),
        ];
    }

    /**
     * @param  PlatformPlan  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): PlatformPlan
    {
        $record->update(PlatformPlanResource::fieldsFrom($data));

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return PlatformPlanResource::getUrl('index');
    }
}
