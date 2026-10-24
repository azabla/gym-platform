<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CheckPaymentLinkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('check', $this->paymentLink());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Chapa can only be asked with the gym's own key.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! ChapaAccount::query()->exists()) {
                    $validator->errors()->add('chapa_account', 'Connect the gym\'s Chapa account first.');
                }
            },
        ];
    }

    public function chapaAccount(): ChapaAccount
    {
        return ChapaAccount::query()->firstOrFail();
    }

    private function paymentLink(): PaymentLink
    {
        return $this->route('payment_link');
    }
}
