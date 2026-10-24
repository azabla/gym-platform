<?php

namespace App\Http\Requests\Api\V1\CheckIns;

use App\Domains\Attendance\Models\CheckIn;
use App\Http\Requests\Api\V1\Concerns\AtABranchTheStaffMemberWorksAt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeskCheckInRequest extends FormRequest
{
    use AtABranchTheStaffMemberWorksAt;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', CheckIn::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
        ];
    }

    /**
     * Get the custom messages for the validator's rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Choose the branch the member is coming into.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->useTheOnlyBranchByDefault();
    }
}
