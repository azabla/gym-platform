<?php

namespace App\Rules;

use App\Domains\Shared\Money\Exceptions\InvalidMoneyAmount;
use App\Domains\Shared\Money\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An amount of money written as text, such as "2,500" or "2500.50", never a
 * JSON number, because a number may already have lost precision (ADR 0001).
 * Negative amounts are refused.
 */
class ValidMoneyAmount implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $amount = Money::of(is_string($value) ? $value : '');
        } catch (InvalidMoneyAmount) {
            $fail('The :attribute must be an amount written as text, like "2,500" or "2500.50".');

            return;
        }

        if ($amount->isNegative()) {
            $fail('The :attribute must not be negative.');
        }
    }
}
