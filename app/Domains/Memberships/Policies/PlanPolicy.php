<?php

declare(strict_types=1);

namespace App\Domains\Memberships\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Every staff member of a gym may see its plans. Owners and managers may also
 * create plans, change them (including the price) and archive them. See ADR 0006.
 */
final class PlanPolicy
{
    private const ROLES_THAT_MANAGE_PLANS = [
        StaffRole::Owner,
        StaffRole::Manager,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->staffFor($user) !== null;
    }

    public function view(User $user, Plan $plan): bool
    {
        return $this->staffFor($user, $plan) !== null;
    }

    public function create(User $user): bool
    {
        return $this->managesPlans($this->staffFor($user));
    }

    public function update(User $user, Plan $plan): bool
    {
        return $this->managesPlans($this->staffFor($user, $plan));
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $this->managesPlans($this->staffFor($user, $plan));
    }

    /**
     * The user's staff record in the current gym, if they are acting there
     * (and the plan, when given, belongs to that gym).
     */
    private function staffFor(User $user, ?Plan $plan = null): ?Staff
    {
        $staff = $this->currentStaff->get();

        if (! $staff->user()->is($user)) {
            return null;
        }

        if ($plan !== null && $plan->organization_id !== $staff->organization_id) {
            return null;
        }

        return $staff;
    }

    private function managesPlans(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::ROLES_THAT_MANAGE_PLANS, true);
    }
}
