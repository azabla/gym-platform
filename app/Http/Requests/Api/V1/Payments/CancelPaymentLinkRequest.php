<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class CancelPaymentLinkRequest extends FormRequest
{
    /**
     * A link of a branch the staff member doesn't work at is a 404 (ADR 0025).
     */
    public function authorize(): Response
    {
        return Gate::inspect('cancel', $this->paymentLink());
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
