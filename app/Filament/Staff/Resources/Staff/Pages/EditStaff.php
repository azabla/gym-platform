<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Staff\Pages;

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Exceptions\StaffRefused;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\Resources\Staff\StaffResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Change a staff member's role or branches, archive or restore them, or
 * send their invite again.
 */
class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StaffResource::resendInviteAction(),
            StaffResource::restoreAction(),
            StaffResource::archiveAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $staff = $this->getRecord();

        assert($staff instanceof Staff);

        return [
            ...$data,
            'name' => $staff->user->name,
            'phone' => $staff->user->phone,
            'role' => $staff->role->value,
            'branch_ids' => $staff->limitedToBranchIds() ?? [],
        ];
    }

    /**
     * @param  Staff  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Staff
    {
        $role = StaffRole::from((string) $data['role']);
        $branches = StaffResource::branchesIn($data);

        StaffResource::refuseWhatMayNotBeGiven($role, $branches);

        try {
            $record->reassign($role, ...$branches);
        } catch (StaffRefused $refusal) {
            throw StaffResource::refusalOnTheForm($refusal);
        }

        return $record;
    }
}
