<?php

declare(strict_types=1);

namespace App\Filament\Staff\Auth;

use App\Filament\Concerns\SignsInWithPhone;
use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Filament's sign-in page, with the phone number staff were added with
 * instead of an email, matched however it was typed. See ADR 0019.
 */
class Login extends BaseLogin
{
    use SignsInWithPhone;
}
