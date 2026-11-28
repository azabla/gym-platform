<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\ExpenseCategory;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Owners and accountants set the gym's expense categories; managers, who
 * record their branches' expenses, choose from them. See ADR 0047.
 */
final class ExpenseCategoryPolicy
{
    private const ROLES_THAT_SEE = [StaffRole::Owner, StaffRole::Accountant, StaffRole::Manager];

    private const ROLES_THAT_MANAGE = [StaffRole::Owner, StaffRole::Accountant];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_SEE);
    }

    public function create(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_MANAGE);
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        $staff = $this->staffFor($user);

        return $this->hasRole($staff, self::ROLES_THAT_MANAGE) && $category->organization_id === $staff?->organization_id;
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return $this->update($user, $category);
    }

    public function restore(User $user, ExpenseCategory $category): bool
    {
        return $this->update($user, $category);
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
