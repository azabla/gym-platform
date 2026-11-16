<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Http\Requests\Api\V1\Concerns\AtTheStaffMembersBranches;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The payments list covers the branch asked for, which must be one the staff
 * member works at, or else all of theirs (ADR 0025).
 */
class ListPaymentsRequest extends FormRequest
{
    use AtTheStaffMembersBranches;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Payment::class);
    }

    /**
     * The filters: the first and last day paid (both included), the branch,
     * the method and the member.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'branch_id' => $this->branchRules(),
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'member_id' => ['nullable', 'integer'],
        ];
    }
}
