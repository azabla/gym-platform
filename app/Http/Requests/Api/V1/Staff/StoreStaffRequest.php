<?php

namespace App\Http\Requests\Api\V1\Staff;

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Http\Requests\Api\V1\Staff\Concerns\GivesARoleAtBranches;
use App\Rules\ValidPhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStaffRequest extends FormRequest
{
    use GivesARoleAtBranches;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Staff::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', new ValidPhoneNumber],
            'role' => ['required', Rule::enum(StaffRole::class)],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkTheRoleAndBranchesMayBeGiven($validator, $this->role()),
        ];
    }

    /**
     * The branches sent, or none for all branches.
     *
     * @return list<Branch>
     */
    public function branches(): array
    {
        return $this->branchesSent();
    }

    public function role(): StaffRole
    {
        return StaffRole::from($this->string('role')->toString());
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::of($this->string('phone')->toString());
    }
}
