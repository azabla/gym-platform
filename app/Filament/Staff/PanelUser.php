<?php

declare(strict_types=1);

namespace App\Filament\Staff;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Enums\SuspensionCause;
use App\Domains\Organizations\Models\GymSuspension;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A user signed in to the staff panel. Identity doesn't know about gyms, so the
 * panel's view of a user lives here: the gyms they work at are their tenants.
 * See ADR 0017.
 */
#[Table('users')]
class PanelUser extends User implements FilamentUser, HasTenants
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->staffRecords()->exists();
    }

    /**
     * @return Collection<int, Organization>
     */
    public function getTenants(Panel $panel): Collection
    {
        // Gyms that aren't paused first, so signing in lands on one (ADR 0043).
        return Organization::query()
            ->whereIn('id', $this->staffRecords()->select('organization_id'))
            ->orderByRaw('suspended_at IS NOT NULL')
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether every gym the user works at is paused, so there is nowhere to
     * sign in to (ADR 0043). An owner of a gym the platform paused for an
     * unpaid bill still signs in, to see what to pay (ADR 0045).
     */
    public function worksOnlyAtPausedGyms(): bool
    {
        $worksAtAnActiveGym = Organization::query()
            ->active()
            ->whereIn('id', $this->staffRecords()->select('organization_id'))
            ->exists();

        return ! $worksAtAnActiveGym && ! $this->ownsAGymPausedForAnUnpaidBill();
    }

    private function ownsAGymPausedForAnUnpaidBill(): bool
    {
        return GymSuspension::query()->acrossOrganizations()
            ->whereNull('resumed_at')
            ->where('cause', SuspensionCause::UnpaidBill)
            ->whereIn('organization_id', $this->staffRecords()->where('role', StaffRole::Owner)->select('organization_id'))
            ->exists();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Organization
            && $this->staffRecords()->where('organization_id', $tenant->getKey())->exists();
    }

    /**
     * The user's staff records in every gym.
     *
     * @return Builder<Staff>
     */
    private function staffRecords(): Builder
    {
        return Staff::query()->acrossOrganizations()->where('user_id', $this->getKey());
    }
}
