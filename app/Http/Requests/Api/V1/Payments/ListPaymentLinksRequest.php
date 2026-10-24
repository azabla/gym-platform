<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPaymentLinksRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', PaymentLink::class);
    }

    /**
     * The filters: the status and the member.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(PaymentLinkStatus::class)],
            'member_id' => ['nullable', 'integer'],
        ];
    }
}
