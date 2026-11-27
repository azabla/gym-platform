<?php

declare(strict_types=1);

namespace App\Domains\Imports\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Bringing in a gym's members from a file is for its owners and managers,
 * who set the gym up; others don't see the imports, which hold every row's
 * name and phone. See ADR 0044.
 */
final class MemberImportPolicy
{
    private const ROLES_THAT_IMPORT = [StaffRole::Owner, StaffRole::Manager];

    public function __construct(private CurrentStaff $currentStaff) {}

    public function viewAny(User $user): bool
    {
        return $this->imports($this->staffFor($user));
    }

    public function view(User $user, MemberImport $import): bool
    {
        $staff = $this->staffFor($user);

        return $this->imports($staff) && $import->organization_id === $staff?->organization_id;
    }

    public function create(User $user): bool
    {
        return $this->imports($this->staffFor($user));
    }

    public function update(User $user, MemberImport $import): bool
    {
        return $this->view($user, $import);
    }

    private function staffFor(User $user): ?Staff
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) ? $staff : null;
    }

    private function imports(?Staff $staff): bool
    {
        return $staff !== null && in_array($staff->role, self::ROLES_THAT_IMPORT, true);
    }
}
