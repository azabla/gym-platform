<?php

namespace App\Http\Requests\Api\V1;

use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Rules\ValidPhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class IssueTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Staff sign in with their phone; older accounts may use their email
     * instead (ADR 0019).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required_without:email', 'nullable', 'string', new ValidPhoneNumber],
            'email' => ['required_without:phone', 'nullable', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * The field the user signs in with, and its value as stored: the phone in
     * E.164 form, or the email in lowercase.
     *
     * @return array{0: 'phone'|'email', 1: string}
     */
    public function identifier(): array
    {
        if ($this->filled('phone')) {
            return ['phone', PhoneNumber::of($this->string('phone')->toString())->e164];
        }

        return ['email', $this->string('email')->toString()];
    }

    /**
     * Emails are stored in lowercase; phone keyboards often capitalise the first letter.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }
}
