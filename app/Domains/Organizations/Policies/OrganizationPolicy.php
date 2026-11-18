<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentStaff;

/**
 * Who sees and changes the gym's own settings, such as the language its
 * members get messages in (ADR 0034).
 */
final class OrganizationPolicy
{
    public function __construct(private CurrentStaff $currentStaff) {}

    /**
     * Every staff member of the gym sees its settings.
     */
    public function view(User $user, Organization $organization): bool
    {
        $staff = $this->currentStaff->get();

        return $staff->user()->is($user) && $staff->organization_id === $organization->id;
    }

    /**
     * Owners change them.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization) && $this->currentStaff->get()->role === StaffRole::Owner;
    }
}
