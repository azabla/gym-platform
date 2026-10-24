<?php

declare(strict_types=1);

namespace App\Domains\Billing\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Only the owner connects or disconnects the gym's Chapa account, because the
 * key decides where members' money goes. Owners, managers and accountants
 * may see whether it is connected. See ADR 0009.
 */
final class ChapaAccountPolicy
{
    private const ROLES_THAT_SEE_THE_ACCOUNT = [
        StaffRole::Owner,
        StaffRole::Manager,
        StaffRole::Accountant,
    ];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function view(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), self::ROLES_THAT_SEE_THE_ACCOUNT);
    }

    /**
     * Connect the gym's Chapa account, or replace its key.
     */
    public function connect(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), [StaffRole::Owner]);
    }

    public function disconnect(User $user): bool
    {
        return $this->hasRole($this->staffFor($user), [StaffRole::Owner]);
    }

    /**
     * The user's staff record in the current gym, if they are acting there.
     */
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
