<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Closure;

/**
 * The branch rules shared by every request that happens at a branch: taking
 * money, a payment link and a check-in. The branch must be one the staff
 * member works at, and is that branch by default when they work at only one.
 */
trait AtABranchTheStaffMemberWorksAt
{
    public function branch(): Branch
    {
        return Branch::query()->findOrFail($this->integer('branch_id'));
    }

    protected function staff(): Staff
    {
        return app(CurrentStaff::class)->get();
    }

    /**
     * Staff who work at only one branch work there unless they say otherwise.
     */
    protected function useTheOnlyBranchByDefault(): void
    {
        $branchesWorkedAt = $this->staff()->branchesWorkedAt();

        if (! $this->filled('branch_id') && $branchesWorkedAt->count() === 1) {
            $this->merge(['branch_id' => $branchesWorkedAt->sole()->id]);
        }
    }

    /**
     * The branch must be a branch of this gym that the staff member works at.
     */
    protected function branchTheStaffMemberWorksAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = is_numeric($value) ? Branch::query()->find((int) $value) : null;

            if ($branch === null || ! $this->staff()->worksAt($branch)) {
                $fail('Choose a branch of this gym that you work at.');
            }
        };
    }
}
