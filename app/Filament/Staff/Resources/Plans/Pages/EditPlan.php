<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Plans\Pages;

use App\Domains\Memberships\Models\Plan;
use App\Filament\Staff\Resources\Plans\PlanResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlanResource::archiveAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $plan = $this->getRecord();

        assert($plan instanceof Plan);

        return [
            ...$data,
            'price' => $plan->price->toDecimal(),
            'duration_unit' => $plan->duration_unit->value,
            'branch_ids' => $plan->limitedToBranchIds() ?? [],
        ];
    }

    /**
     * @param  Plan  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Plan
    {
        return PlanResource::savePlan($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
