<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Models\PaymentLink;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class SendPaymentLinkRequest extends FormRequest
{
    /**
     * The staff who take payments, at the link's branch; another branch's
     * link is a 404 (ADR 0025 and ADR 0031).
     */
    public function authorize(): Response
    {
        return Gate::inspect('send', $this->paymentLink());
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
     * Only an open link, not texted in the last few minutes.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $refusal = $this->paymentLink()->refusalToSendBySms(CarbonImmutable::now());

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
