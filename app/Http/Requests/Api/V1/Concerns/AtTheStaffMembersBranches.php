<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Closure;

/**
 * One branch the staff member works at, from ?branch_id=, or else all of
 * theirs. Lists, the dashboard and the reports read it the same way, so staff
 * limited to some branches see only those (ADR 0025).
 */
trait AtTheStaffMembersBranches
{
    /**
     * The branches covered: the one asked for, or else the ones the staff
     * member works at. Null means every branch.
     *
     * @return list<int>|null
     */
    public function branchIds(): ?array
    {
        if ($this->filled('branch_id')) {
            return [$this->integer('branch_id')];
        }

        return $this->staff()->limitedToBranchIds();
    }

    protected function staff(): Staff
    {
        return app(CurrentStaff::class)->get();
    }

    /**
     * @return list<mixed>
     */
    protected function branchRules(): array
    {
        return ['nullable', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()];
    }

    private function branchTheStaffMemberWorksAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = is_numeric($value) ? Branch::query()->find((int) $value) : null;

            if ($branch === null || ! $this->staff()->worksAt($branch)) {
                $fail('Choose a branch of this gym that you work at.');
            }
        };
    }
}
