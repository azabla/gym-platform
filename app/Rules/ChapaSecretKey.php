<?php

namespace App\Rules;

use App\Domains\Billing\Chapa\ChapaClient;
use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A gym's Chapa secret key that Chapa accepts, for the API and the staff
 * panel alike. Chapa's public key (CHAPUBK…) sits next to the secret key on
 * the dashboard and is easy to paste by mistake. When Chapa doesn't answer,
 * ChapaRequestFailed is thrown instead, because the key may well be right
 * (the API answers 502). See ADR 0009 and ADR 0029.
 */
class ChapaSecretKey implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     *
     * @throws ChapaRequestFailed
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secretKey = trim(is_string($value) ? $value : '');

        if (str_starts_with($secretKey, 'CHAPUBK')) {
            $fail('That is the public key. Paste the secret key, which starts with CHASECK.');

            return;
        }

        try {
            app(ChapaClient::class)->checkKey($secretKey);
        } catch (ChapaRequestFailed $exception) {
            if (! $exception->refusedByChapa) {
                throw $exception;
            }

            $fail('Chapa didn\'t accept this key. Copy the whole secret key from your Chapa dashboard.');
        }
    }
}
