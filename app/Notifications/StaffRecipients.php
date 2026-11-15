<?php

namespace App\Notifications;

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who among a gym's staff hears about something, by role and branch (ADR
 * 0027). Trainers hear nothing, and archived staff are left out. Run inside
 * the gym (CurrentOrganization).
 */
final class StaffRecipients
{
    /**
     * Money taken or owed at the branch: owners, managers and accountants who
     * work there.
     *
     * @return Collection<int, Staff>
     */
    public static function forMoneyAt(Branch $branch): Collection
    {
        return self::withRolesAt([StaffRole::Owner, StaffRole::Manager, StaffRole::Accountant], [$branch->getKey()]);
    }

    /**
     * Members of the branch, by home branch: owners, managers and
     * receptionists who work there.
     *
     * @return Collection<int, Staff>
     */
    public static function forMembersAt(Branch $branch): Collection
    {
        return self::withRolesAt(self::ROLES_FOR_MEMBERS, [$branch->getKey()]);
    }

    /**
     * Everyone who hears about members, at whatever branches they work: the
     * morning list is counted for each of them.
     *
     * @return Collection<int, Staff>
     */
    public static function forMembersAnywhere(): Collection
    {
        return self::withRolesAt(self::ROLES_FOR_MEMBERS, null);
    }

    /** The roles that hear about members. */
    private const ROLES_FOR_MEMBERS = [StaffRole::Owner, StaffRole::Manager, StaffRole::Receptionist];

    /**
     * @param  list<StaffRole>  $roles
     * @param  list<int>|null  $branchIds
     * @return Collection<int, Staff>
     */
    private static function withRolesAt(array $roles, ?array $branchIds): Collection
    {
        return Staff::query()
            ->whereIn('role', $roles)
            ->atAnyOfBranches($branchIds)
            ->orderBy('id')
            ->get();
    }
}
