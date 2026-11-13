<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Models\Payment;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class VoidPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    /**
     * A payment taken at a branch the staff member doesn't work at is a 404,
     * as if it weren't there (ADR 0025).
     */
    public function authorize(): Response
    {
        return Gate::inspect('void', $this->payment());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * The payment mustn't be voided already, and voiding it mustn't leave more
     * refunded than paid (see Payment::refusalToVoid()).
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $refusal = $this->payment()->refusalToVoid();

                if ($refusal !== null) {
                    $validator->errors()->add($refusal->field, $refusal->getMessage());
                }
            },
        ];
    }

    private function payment(): Payment
    {
        return $this->route('payment');
    }
}
