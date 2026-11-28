<?php

declare(strict_types=1);

namespace App\Domains\Activity\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Who did what with the gym's money is for its owners, who see it all, and
 * its managers, who see their branches' and the gym-wide records. The rest
 * of the staff don't see it. See ADR 0046.
 */
final class ActivityRecordPolicy
{
    private const ROLES_THAT_SEE_THE_LOG = [StaffRole::Owner, StaffRole::Manager];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        $staff = $this->staffFor($user);

        return $staff !== null && in_array($staff->role, self::ROLES_THAT_SEE_THE_LOG, true);
    }

    private function staffFor(User $user): ?Staff
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) ? $staff : null;
    }
}
