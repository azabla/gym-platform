<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Who may see and manage a gym's staff (ADR 0019): owners manage everyone;
 * managers manage receptionists, trainers and accountants who work only at
 * branches they work at too. No one changes or archives themselves, so no
 * one can lock themselves out. Which role and branches may be given is
 * checked by the requests, against the same rules.
 */
final class StaffPolicy
{
    public function __construct(private CurrentStaff $currentStaff) {}

    /**
     * Owners and managers see the staff; a manager limited to some branches
     * sees only staff at those branches.
     */
    public function viewAny(User $user): bool
    {
        $actor = $this->currentStaff->get();

        return $actor->user()->is($user) && $actor->role->managesStaff();
    }

    public function view(User $user, Staff $staff): bool
    {
        $actor = $this->currentStaff->get();

        if (! $this->viewAny($user)) {
            return false;
        }

        $actorBranchIds = $actor->limitedToBranchIds();
        $staffBranchIds = $staff->limitedToBranchIds();

        return $actorBranchIds === null
            || $staffBranchIds === null
            || array_intersect($actorBranchIds, $staffBranchIds) !== [];
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Staff $staff): bool
    {
        $actor = $this->currentStaff->get();

        return $this->viewAny($user)
            && ! $actor->is($staff)
            && $staff->role->isManagedBy($actor->role)
            && $actor->covers($staff);
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $this->update($user, $staff);
    }

    public function restore(User $user, Staff $staff): bool
    {
        return $this->update($user, $staff);
    }
}
