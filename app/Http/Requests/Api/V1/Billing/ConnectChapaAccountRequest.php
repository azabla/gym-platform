<?php

namespace App\Http\Requests\Api\V1\Billing;

use App\Domains\Billing\Chapa\ChapaClient;
use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Models\ChapaAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConnectChapaAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('connect', ChapaAccount::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'secret_key' => ['required', 'bail', 'string', 'max:255', $this->notThePublicKey()],
        ];
    }

    /**
     * Chapa must accept the key. When Chapa doesn't answer, the request fails
     * with a 502 instead, because the key may well be right.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    app(ChapaClient::class)->checkKey($this->string('secret_key')->toString());
                } catch (ChapaRequestFailed $exception) {
                    if (! $exception->refusedByChapa) {
                        throw $exception;
                    }

                    $validator->errors()->add('secret_key', 'Chapa didn\'t accept this key. Copy the whole secret key from your Chapa dashboard.');
                }
            },
        ];
    }

    /**
     * Keys are often pasted with spaces or a line break around them.
     */
    protected function prepareForValidation(): void
    {
        $secretKey = $this->input('secret_key');

        if (is_string($secretKey)) {
            $this->merge(['secret_key' => trim($secretKey)]);
        }
    }

    /**
     * Chapa's public key (CHAPUBK…) sits next to the secret key on the
     * dashboard, and is easy to paste by mistake.
     */
    private function notThePublicKey(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (str_starts_with((string) $value, 'CHAPUBK')) {
                $fail('That is the public key. Paste the secret key, which starts with CHASECK.');
            }
        };
    }
}
