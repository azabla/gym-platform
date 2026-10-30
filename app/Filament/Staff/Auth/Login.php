<?php

declare(strict_types=1);

namespace App\Filament\Staff\Auth;

use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Rules\ValidPhoneNumber;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Filament's sign-in page, with the phone number staff were added with
 * instead of an email, matched however it was typed. See ADR 0019.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('Phone number')
            ->tel()
            ->required()
            ->rule(new ValidPhoneNumber)
            ->autocomplete('tel')
            ->autofocus();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        try {
            $phone = PhoneNumber::of((string) $data['phone'])->e164;
        } catch (InvalidPhoneNumber) {
            $this->throwFailureValidationException();
        }

        return [
            'phone' => $phone,
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.phone' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
