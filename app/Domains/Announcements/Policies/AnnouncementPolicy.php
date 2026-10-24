<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Policies;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Every staff member can see the gym's announcements, so the desk knows what
 * members were told. Only owners and managers speak for the gym. Those
 * limited to some branches may only change an announcement to one of their
 * branches, never one to the whole gym. See ADR 0015.
 */
final class AnnouncementPolicy
{
    private const OWNERS_AND_MANAGERS = [StaffRole::Owner, StaffRole::Manager];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->staffFor($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->speaksForTheGym($this->staffFor($user));
    }

    public function update(User $user, Announcement $announcement): bool
    {
        $staff = $this->staffFor($user, $announcement);

        if (! $this->speaksForTheGym($staff)) {
            return false;
        }

        return $announcement->branch === null
            ? $staff->works_at_all_branches
            : $staff->worksAt($announcement->branch);
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the announcement, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?Announcement $announcement = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($announcement !== null && $announcement->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    /**
     * @phpstan-assert-if-true Staff $staff
     */
    private function speaksForTheGym(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::OWNERS_AND_MANAGERS, true);
    }
}
