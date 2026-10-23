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
     * Only the fields that are sent are validated and changed. The emergency
     * contact's name and phone are sent together, so an edit never leaves half a contact.
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
            'emergency_contact_name' => ['nullable', 'bail', 'string', 'max:255', 'present_with:emergency_contact_phone', 'required_with:emergency_contact_phone'],
            'emergency_contact_phone' => ['nullable', 'bail', 'string', new ValidPhoneNumber, 'present_with:emergency_contact_name', 'required_with:emergency_contact_name'],
            'home_branch_id' => ['sometimes', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
        ];
    }
}
