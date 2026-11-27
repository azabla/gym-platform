<?php

declare(strict_types=1);

namespace App\Filament\Admin\Auth;

use App\Filament\Concerns\SignsInWithPhone;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * The admin panel's sign-in page: platform admins sign in with their phone
 * and password, as staff do. See ADR 0042.
 */
class Login extends BaseLogin
{
    use SignsInWithPhone;
}
