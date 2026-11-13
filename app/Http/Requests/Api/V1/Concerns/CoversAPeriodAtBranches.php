<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Validator;

/**
 * A period of the gym's days, today unless ?from= and ?to= say otherwise and
 * at most a year long, at one branch the staff member works at or at all of
 * theirs. The dashboard and the reports read it the same way (ADR 0014 and
 * ADR 0024).
 */
trait CoversAPeriodAtBranches
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function periodRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
        ];
    }

    /**
     * The period ends on or after it starts, and is at most a year long.
     */
    protected function checkThePeriod(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($this->to()->lessThan($this->from())) {
            $validator->errors()->add('to', 'The period must end on or after the day it starts.');
        } elseif ($this->from()->diffInDays($this->to()) >= 366) {
            $validator->errors()->add('to', 'The period can be at most a year long.');
        }
    }

    /**
     * The first day of the period, where the gym is: today unless given.
     */
    public function from(): CarbonImmutable
    {
        return $this->filled('from') ? CarbonImmutable::parse($this->string('from')->toString()) : $this->today();
    }

    /**
     * The last day of the period, where the gym is: the first day unless given.
     */
    public function to(): CarbonImmutable
    {
        return $this->filled('to') ? CarbonImmutable::parse($this->string('to')->toString()) : $this->from();
    }

    public function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }

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
