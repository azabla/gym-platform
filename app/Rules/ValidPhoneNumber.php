<?php

namespace App\Rules;

use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidPhoneNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            PhoneNumber::of(is_string($value) ? $value : '');
        } catch (InvalidPhoneNumber) {
            $fail('The :attribute must be a phone number like 0911 234 567, or an international number starting with +.');
        }
    }
}
