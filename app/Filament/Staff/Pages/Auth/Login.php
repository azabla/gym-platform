<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Filament's sign-in page, matching the email however it was typed, as the API
 * does: emails are stored in lowercase.
 */
class Login extends BaseLogin
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
        ];
    }
}
