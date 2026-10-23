<?php

declare(strict_types=1);

namespace App\Domains\Members\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Every staff member of a gym may look its members up. Owners, managers and
 * receptionists may also register, edit and archive them. See ADR 0005.
 */
final class MemberPolicy
{
    private const ROLES_THAT_MANAGE_MEMBERS = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Receptionist,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->staffFor($user) !== null;
    }

    public function view(User $user, Member $member): bool
    {
        return $this->staffFor($user, $member) !== null;
    }

    public function create(User $user): bool
    {
        return $this->managesMembers($this->staffFor($user));
    }

    public function update(User $user, Member $member): bool
    {
        return $this->managesMembers($this->staffFor($user, $member));
    }

    public function delete(User $user, Member $member): bool
    {
        return $this->managesMembers($this->staffFor($user, $member));
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the member, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?Member $member = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($member !== null && $member->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    private function managesMembers(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::ROLES_THAT_MANAGE_MEMBERS, true);
    }
}
