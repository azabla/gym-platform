<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class CheckPaymentLinkRequest extends FormRequest
{
    /**
     * A link of a branch the staff member doesn't work at is a 404 (ADR 0025).
     */
    public function authorize(): Response
    {
        return Gate::inspect('check', $this->paymentLink());
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
