<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Owners and managers create, change, archive and restore the gym's discount
 * groups, and put members in them or take them out, as they are the ones who
 * may discount at the desk. Groups are gym-wide, like members. See ADR 0032.
 */
final class DiscountGroupPolicy
{
    private const ROLES_THAT_MANAGE_DISCOUNTS = [
        StaffRole::Owner,
        StaffRole::Manager,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->managesDiscounts($this->staffFor($user));
    }

    public function view(User $user, DiscountGroup $discountGroup): bool
    {
        return $this->managesDiscounts($this->staffFor($user, $discountGroup));
    }

    public function create(User $user): bool
    {
        return $this->managesDiscounts($this->staffFor($user));
    }

    public function update(User $user, DiscountGroup $discountGroup): bool
    {
        return $this->managesDiscounts($this->staffFor($user, $discountGroup));
    }

    public function delete(User $user, DiscountGroup $discountGroup): bool
    {
        return $this->update($user, $discountGroup);
    }

    public function restore(User $user, DiscountGroup $discountGroup): bool
    {
        return $this->update($user, $discountGroup);
    }

    /**
     * Put a member in a group, or take them out of theirs.
     */
    public function assign(User $user): bool
    {
        return $this->managesDiscounts($this->staffFor($user));
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the group, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?DiscountGroup $discountGroup = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($discountGroup !== null && $discountGroup->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    private function managesDiscounts(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::ROLES_THAT_MANAGE_DISCOUNTS, true);
    }
}
