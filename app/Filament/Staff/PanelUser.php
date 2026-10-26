<?php

declare(strict_types=1);

namespace App\Filament\Staff;

use App\Domains\Identity\Models\User;
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
        return Organization::query()
            ->whereIn('id', $this->staffRecords()->select('organization_id'))
            ->orderBy('name')
            ->get();
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
