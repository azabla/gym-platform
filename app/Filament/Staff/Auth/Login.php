<?php

declare(strict_types=1);

namespace App\Filament\Staff\Auth;

use App\Domains\Organizations\Exceptions\GymSuspended;
use App\Filament\Concerns\SignsInWithPhone;
use App\Filament\Staff\PanelUser;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Filament's sign-in page, with the phone number staff were added with
 * instead of an email, matched however it was typed (ADR 0019). Someone
 * whose every gym is paused is told so rather than signed in (ADR 0043).
 */
class Login extends BaseLogin
{
    use SignsInWithPhone;

    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        if (! parent::isUserAllowedToAccessPanel($user)) {
            return false;
        }

        if ($user instanceof PanelUser && $user->worksOnlyAtPausedGyms()) {
            throw ValidationException::withMessages(['data.phone' => GymSuspended::make()->getMessage()]);
        }

        return true;
    }
}
