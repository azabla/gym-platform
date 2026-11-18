<?php

namespace App\Notifications;

use App\Domains\Notifications\Enums\StaffNotificationTopic;
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
        return self::withRolesAt(self::ROLES_FOR_MONEY, [$branch->getKey()]);
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

    /**
     * The topics a role hears about, for choosing push and SMS (ADR 0035):
     * money for the roles that hear about money, members for those that hear
     * about members. Trainers hear nothing.
     *
     * @return list<StaffNotificationTopic>
     */
    public static function topicsFor(StaffRole $role): array
    {
        $topics = [];

        if (in_array($role, self::ROLES_FOR_MONEY, true)) {
            $topics[] = StaffNotificationTopic::OnlinePayments;
            $topics[] = StaffNotificationTopic::Refunds;
        }

        if (in_array($role, self::ROLES_FOR_MEMBERS, true)) {
            $topics[] = StaffNotificationTopic::AppFreezes;
            $topics[] = StaffNotificationTopic::MembersToCall;
        }

        return $topics;
    }

    /** The roles that hear about money. */
    private const ROLES_FOR_MONEY = [StaffRole::Owner, StaffRole::Manager, StaffRole::Accountant];

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
