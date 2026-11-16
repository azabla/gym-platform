<?php

namespace App\Http\Requests\Api\V1\Billing;

use App\Domains\Billing\Models\ChapaAccount;
use App\Rules\ChapaSecretKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
     * Get the validation rules that apply to the request. Chapa must accept
     * the key; when Chapa doesn't answer, the request fails with a 502
     * instead, because the key may well be right.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'secret_key' => ['required', 'bail', 'string', 'max:255', new ChapaSecretKey],
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
}
