<?php

namespace App\Http\Requests\Api\V1\Members;

use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Rules\ValidPhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Member::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'bail', 'string', new ValidPhoneNumber, $this->uniquePhoneInGym()],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255', 'required_with:emergency_contact_phone'],
            'emergency_contact_phone' => ['nullable', 'bail', 'string', new ValidPhoneNumber, 'required_with:emergency_contact_name'],
            'home_branch_id' => ['required', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
        ];
    }

    /**
     * Phone numbers are compared in E.164 form, however they were typed.
     * Numbers that can't be read are left as typed, so ValidPhoneNumber reports them.
     */
    protected function prepareForValidation(): void
    {
        foreach (['phone', 'emergency_contact_phone'] as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            try {
                $this->merge([$field => PhoneNumber::of($value)->e164]);
            } catch (InvalidPhoneNumber) {
            }
        }
    }

    /**
     * One active member per phone number in each gym.
     */
    protected function uniquePhoneInGym(): Unique
    {
        return Rule::unique('members', 'phone')
            ->where('organization_id', app(CurrentOrganization::class)->id())
            ->whereNull('deleted_at');
    }

    /**
     * The home branch must be a branch of this gym that the staff member works at.
     */
    protected function branchTheStaffMemberWorksAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = is_numeric($value) ? Branch::query()->find((int) $value) : null;

            if ($branch === null || ! app(CurrentStaff::class)->get()->worksAt($branch)) {
                $fail('Choose a branch of this gym that you work at.');
            }
        };
    }
}
