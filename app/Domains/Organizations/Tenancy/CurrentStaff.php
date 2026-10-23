<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Tenancy;

use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\Exceptions\NoCurrentStaff;
use Illuminate\Container\Attributes\Scoped;

/**
 * The signed-in user's staff record in the current organization. It is set for
 * organization routes, and policies read it to check the staff member's role
 * and branches. Scoped, like CurrentOrganization. See ADR 0004.
 */
#[Scoped]
final class CurrentStaff
{
    private ?Staff $staff = null;

    public function set(Staff $staff): void
    {
        $this->staff = $staff;
    }

    public function get(): Staff
    {
        return $this->staff ?? throw NoCurrentStaff::isSet();
    }
}
