<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Staff\Pages;

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Exceptions\StaffRefused;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Filament\Staff\Resources\Staff\StaffResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

/**
 * Add someone to the staff by phone, as the API does: someone who already
 * uses the app keeps their account, and they get an SMS to set their
 * password or, if they have one, to say they were added.
 */
class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Staff
    {
        $role = StaffRole::from((string) $data['role']);
        $branches = StaffResource::branchesIn($data);

        StaffResource::refuseWhatMayNotBeGiven($role, $branches);

        try {
            $staff = DB::transaction(function () use ($data, $role, $branches): Staff {
                $user = User::forPhone(PhoneNumber::of((string) $data['phone']), (string) $data['name']);

                return Staff::add($user, $role, ...$branches);
            });
        } catch (StaffRefused $refusal) {
            throw StaffResource::refusalOnTheForm($refusal);
        }

        PasswordLink::invite($staff->user, app(CurrentStaff::class)->get()->organization->name);

        return $staff;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Staff member added. An SMS is on its way to them.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
