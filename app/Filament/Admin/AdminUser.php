<?php

declare(strict_types=1);

namespace App\Filament\Admin;

use App\Domains\Identity\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * A user signed in to the admin panel: only platform admins, made by the
 * platform:admins command, may open it. Working at a gym gives no way in,
 * and being an admin gives no way into a gym's staff panel. See ADR 0042.
 */
#[Table('users')]
class AdminUser extends User implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_platform_admin === true;
    }
}
