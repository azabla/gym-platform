<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelPaymentLinkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('cancel', $this->paymentLink());
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
     * Only a link still waiting for the member to pay can be cancelled.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->paymentLink()->status !== PaymentLinkStatus::Pending) {
                    $validator->errors()->add('payment_link', 'Only an open link can be cancelled.');
                }
            },
        ];
    }

    private function paymentLink(): PaymentLink
    {
        return $this->route('payment_link');
    }
}
