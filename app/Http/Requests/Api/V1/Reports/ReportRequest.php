<?php

namespace App\Http\Requests\Api\V1\Reports;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Http\Requests\Api\V1\Concerns\CoversAPeriodAtBranches;
use App\Reports\ReportPeriod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A report for a period of the gym's days at the staff member's branches,
 * read as the dashboard reads them: today unless ?from= and ?to= say
 * otherwise, at most a year, at one branch they work at or all of theirs.
 * See ADR 0024.
 */
abstract class ReportRequest extends FormRequest
{
    use CoversAPeriodAtBranches;

    /**
     * The period ends on or after it starts, and is at most a year long.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkThePeriod($validator),
        ];
    }

    public function period(): ReportPeriod
    {
        return new ReportPeriod(app(CurrentOrganization::class)->get(), $this->from(), $this->to(), $this->branchIds());
    }
}
