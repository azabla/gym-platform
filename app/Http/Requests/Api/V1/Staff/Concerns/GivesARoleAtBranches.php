<?php

namespace App\Http\Requests\Api\V1\Staff\Concerns;

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Illuminate\Validation\Validator;

/**
 * The role and branches given to a staff member: the role must be one the
 * signed-in staff member manages, and the branches ones they work at, all
 * of them when none are given (ADR 0019).
 */
trait GivesARoleAtBranches
{
    /**
     * The branches to give, or none for all branches.
     *
     * @return list<Branch>
     */
    abstract public function branches(): array;

    /**
     * The branches sent, or none for all branches.
     *
     * @return list<Branch>
     */
    protected function branchesSent(): array
    {
        $branchIds = array_map('intval', (array) $this->input('branch_ids', []));

        if ($branchIds === []) {
            return [];
        }

        return array_values(Branch::query()->whereKey($branchIds)->orderBy('name')->get()->all());
    }

    /**
     * After the fields themselves are valid, check that the signed-in staff
     * member may give this role at these branches.
     */
    protected function checkTheRoleAndBranchesMayBeGiven(Validator $validator, StaffRole $role): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $actor = app(CurrentStaff::class)->get();
        $branches = $this->branches();

        if ($this->has('branch_ids') && count($this->branchesSent()) !== count((array) $this->input('branch_ids'))) {
            $validator->errors()->add('branch_ids', 'Choose branches of this gym.');

            return;
        }

        if (! $role->isManagedBy($actor->role)) {
            $validator->errors()->add('role', 'You can\'t give someone this role.');

            return;
        }

        if (! $actor->mayAssign($role, ...$branches)) {
            $validator->errors()->add('branch_ids', 'Choose only branches you work at.');
        }
    }
}
