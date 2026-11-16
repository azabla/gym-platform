<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Models\PaymentLink;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class MarkPaymentLinkRefundedRequest extends FormRequest
{
    /**
     * Owners, managers and accountants at the link's branch; another
     * branch's link is a 404 (ADR 0025 and ADR 0031).
     */
    public function authorize(): Response
    {
        return Gate::inspect('markRefunded', $this->paymentLink());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Only a link needing a refund can be marked refunded.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $refusal = $this->paymentLink()->refusalToMarkRefunded();

                if ($refusal !== null) {
                    $validator->errors()->add($refusal->field, $refusal->getMessage());
                }
            },
        ];
    }

    private function paymentLink(): PaymentLink
    {
        return $this->route('payment_link');
    }
}
