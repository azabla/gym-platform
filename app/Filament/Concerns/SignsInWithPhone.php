<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Rules\ValidPhoneNumber;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Filament's sign-in page with the phone number someone was added with
 * instead of an email, matched however it was typed. Used by the staff
 * panel (ADR 0019) and the admin panel (ADR 0042).
 */
trait SignsInWithPhone
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label(__('Phone number'))
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
