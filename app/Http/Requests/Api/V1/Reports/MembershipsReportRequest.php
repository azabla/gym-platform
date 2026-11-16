<?php

namespace App\Http\Requests\Api\V1\Reports;

use App\Domains\Memberships\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;

class MembershipsReportRequest extends ReportRequest
{
    /**
     * Owners, managers and accountants: the roles that see the gym's money.
     */
    public function authorize(): bool
    {
        return $this->user()->can('export', Membership::class);
    }

    /**
     * The period and branch.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->periodRules();
    }
}
