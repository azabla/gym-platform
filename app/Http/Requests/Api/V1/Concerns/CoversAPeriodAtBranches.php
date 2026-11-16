<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * A period of the gym's days, today unless ?from= and ?to= say otherwise and
 * at most a year long, at one branch the staff member works at or at all of
 * theirs. The dashboard and the reports read it the same way (ADR 0014 and
 * ADR 0024).
 */
trait CoversAPeriodAtBranches
{
    use AtTheStaffMembersBranches;

    /**
     * @return array<string, array<mixed>>
     */
    protected function periodRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => $this->branchRules(),
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
}
