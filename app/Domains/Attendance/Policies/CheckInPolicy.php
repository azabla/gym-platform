<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Every staff member, trainers included, can check members in and see the
 * check-ins, because anyone may be working the door. See ADR 0011.
 */
final class CheckInPolicy
{
    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->isCurrentStaff($user);
    }

    public function create(User $user): bool
    {
        return $this->isCurrentStaff($user);
    }

    private function isCurrentStaff(User $user): bool
    {
        return $this->currentStaff->get()->user()->is($user);
    }
}
