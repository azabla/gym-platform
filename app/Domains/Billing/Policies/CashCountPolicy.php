<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\CashCount;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Everyone who takes cash closes their own count; owners and managers see
 * everyone's at their branches and mark them checked; accountants see them
 * all but don't check; receptionists see only their own. Trainers don't
 * handle money. See ADR 0046.
 */
final class CashCountPolicy
{
    private const ROLES_THAT_TAKE_CASH = [StaffRole::Owner, StaffRole::Manager, StaffRole::Accountant, StaffRole::Receptionist];

    private const ROLES_THAT_SEE_EVERYONES = [StaffRole::Owner, StaffRole::Manager, StaffRole::Accountant];

    private const ROLES_THAT_CHECK = [StaffRole::Owner, StaffRole::Manager];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_TAKE_CASH);
    }

    public function view(User $user, CashCount $count): bool
    {
        $staff = $this->staffFor($user);

        return $staff !== null
            && $count->organization_id === $staff->organization_id
            && ($count->staff_id === $staff->id || $this->hasRole($staff, self::ROLES_THAT_SEE_EVERYONES));
    }

    /**
     * Close one's own count.
     */
    public function create(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_TAKE_CASH);
    }

    public function check(User $user, CashCount $count): bool
    {
        $staff = $this->staffFor($user);

        return $this->hasRole($staff, self::ROLES_THAT_CHECK) && $this->view($user, $count);
    }

    /**
     * Whether the staff member sees everyone's counts, not only their own.
     */
    public function seeEveryones(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_SEE_EVERYONES);
    }

    private function staffFor(User $user): ?Staff
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) ? $staff : null;
    }

    /**
     * @param  list<StaffRole>  $roles
     */
    private function hasRole(?Staff $staff, array $roles): bool
    {
        return $staff !== null && in_array($staff->role, $roles, true);
    }
}
