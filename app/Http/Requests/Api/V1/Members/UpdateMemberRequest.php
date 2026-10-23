<?php

namespace App\Http\Requests\Api\V1\Members;

use App\Domains\Members\Enums\Gender;
use App\Rules\ValidPhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends StoreMemberRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('member'));
    }

    /**
     * Only the fields that are sent are validated and changed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'bail', 'string', new ValidPhoneNumber, $this->uniquePhoneInGym()->ignore($this->route('member'))],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255', 'required_with:emergency_contact_phone'],
            'emergency_contact_phone' => ['nullable', 'bail', 'string', new ValidPhoneNumber, 'required_with:emergency_contact_name'],
            'home_branch_id' => ['sometimes', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
        ];
    }
}
