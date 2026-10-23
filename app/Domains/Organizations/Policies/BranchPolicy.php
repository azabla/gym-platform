<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;

final class BranchPolicy
{
    public function __construct(private CurrentStaff $currentStaff) {}

    /**
     * Staff may view only the branches they work at.
     */
    public function view(User $user, Branch $branch): bool
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) && $staff->worksAt($branch);
    }
}
