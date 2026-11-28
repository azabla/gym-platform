<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\Expense;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Owners and accountants record and see every expense; managers record and
 * see their branches'; receptionists and trainers don't see expenses or
 * profit. See ADR 0047.
 */
final class ExpensePolicy
{
    private const ROLES_THAT_SPEND = [StaffRole::Owner, StaffRole::Accountant, StaffRole::Manager];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->spends($this->staffFor($user));
    }

    public function view(User $user, Expense $expense): bool
    {
        $staff = $this->staffFor($user);

        return $this->spends($staff) && $staff !== null && $expense->organization_id === $staff->organization_id && Expense::query()->seenBy($staff)->whereKey($expense->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $this->spends($this->staffFor($user));
    }

    public function void(User $user, Expense $expense): bool
    {
        return $this->view($user, $expense);
    }

    private function staffFor(User $user): ?Staff
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) ? $staff : null;
    }

    private function spends(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::ROLES_THAT_SPEND, true);
    }
}
