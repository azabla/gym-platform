<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;

final class BranchPolicy
{
    public function __construct(private CurrentStaff $currentStaff) {}

    /**
     * Every staff member sees the list of the branches they work at.
     */
    public function viewAny(User $user): bool
    {
        return $this->currentStaff->get()->user()->is($user);
    }

    /**
     * Staff may view only the branches they work at.
     */
    public function view(User $user, Branch $branch): bool
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) && $staff->worksAt($branch);
    }

    /**
     * Owners add branches (ADR 0022).
     */
    public function create(User $user): bool
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) && $staff->role === StaffRole::Owner;
    }

    /**
     * Owners and managers who work at the branch change its details and set
     * its opening hours (ADR 0016 and ADR 0022).
     */
    public function update(User $user, Branch $branch): bool
    {
        $staff = $this->currentStaff->get();

        return $this->view($user, $branch) && in_array($staff->role, [StaffRole::Owner, StaffRole::Manager], true);
    }
}
