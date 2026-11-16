<?php

namespace App\Http\Requests\Api\V1\CheckIns;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Http\Requests\Api\V1\Concerns\AtTheStaffMembersBranches;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The check-ins list covers the branch asked for, which must be one the staff
 * member works at, or else all of theirs (ADR 0025).
 */
class ListCheckInsRequest extends FormRequest
{
    use AtTheStaffMembersBranches;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', CheckIn::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'on' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => $this->branchRules(),
            'result' => ['nullable', Rule::enum(CheckInResult::class)],
            'member_id' => ['nullable', 'integer'],
        ];
    }
}
