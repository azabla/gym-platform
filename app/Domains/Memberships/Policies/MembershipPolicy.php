<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Every staff member of a gym may see its memberships. Owners, managers and
 * receptionists may sell them. Only owners and managers may cancel a membership
 * or start one on a past date, so a sale can't be quietly undone or redated
 * after the money is taken. See ADR 0006.
 */
final class MembershipPolicy
{
    private const ROLES_THAT_SELL_MEMBERSHIPS = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Receptionist,
    ];

    private const ROLES_THAT_CANCEL_AND_BACKDATE_MEMBERSHIPS = [
        StaffRole::Owner,
        StaffRole::Manager,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->staffFor($user) !== null;
    }

    public function view(User $user, Membership $membership): bool
    {
        return $this->staffFor($user, $membership) !== null;
    }

    public function create(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_SELL_MEMBERSHIPS);
    }

    /**
     * Start a membership on a past date, for example to enter memberships that
     * members already had before the gym started using the platform.
     */
    public function backdate(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_CANCEL_AND_BACKDATE_MEMBERSHIPS);
    }

    public function cancel(User $user, Membership $membership): bool
    {
        return $this->hasRole($this->staffFor($user, $membership), self::ROLES_THAT_CANCEL_AND_BACKDATE_MEMBERSHIPS);
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the membership, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?Membership $membership = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($membership !== null && $membership->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    /**
     * @param  list<StaffRole>  $roles
     */
    private function hasRole(?Staff $staff, array $roles): bool
    {
        return $staff !== null && in_array($staff->role, $roles, true);
    }
}
