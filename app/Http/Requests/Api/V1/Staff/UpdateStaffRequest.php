<?php

namespace App\Http\Requests\Api\V1\Staff;

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Http\Requests\Api\V1\Staff\Concerns\GivesARoleAtBranches;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStaffRequest extends FormRequest
{
    use GivesARoleAtBranches;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->staffMember());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(StaffRole::class)],
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
     * The role sent, or the staff member's current one.
     */
    public function role(): StaffRole
    {
        return $this->has('role')
            ? StaffRole::from($this->string('role')->toString())
            : $this->staffMember()->role;
    }

    /**
     * The branches sent, or the staff member's current ones; none for all.
     *
     * @return list<Branch>
     */
    public function branches(): array
    {
        if ($this->has('branch_ids')) {
            return $this->branchesSent();
        }

        $staff = $this->staffMember();

        return $staff->works_at_all_branches ? [] : array_values($staff->branchesWorkedAt()->all());
    }

    private function staffMember(): Staff
    {
        $staff = $this->route('staff');

        assert($staff instanceof Staff);

        return $staff;
    }
}
