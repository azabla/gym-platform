<?php

namespace App\Http\Requests\Api\V1\Reports;

use App\Http\Requests\Api\V1\Concerns\AtTheStaffMembersBranches;
use App\Insights\GymInsights;
use App\Insights\Insight;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InsightRequest extends FormRequest
{
    use AtTheStaffMembersBranches;

    /**
     * The roles the insight is for (ADR 0040).
     */
    public function authorize(): bool
    {
        return $this->user()->can('see-insight', $this->insight());
    }

    /**
     * The last 3, 6 or 12 months (12 unless given), at one branch the staff
     * member works at or all of theirs.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'months' => ['nullable', 'integer', Rule::in(GymInsights::MONTHS)],
            'branch_id' => $this->branchRules(),
        ];
    }

    /**
     * The insight named in the address; an unknown one is not found.
     */
    public function insight(): Insight
    {
        $insight = $this->route('insight');

        if ($insight instanceof Insight) {
            return $insight;
        }

        return Insight::tryFrom(is_string($insight) ? $insight : '') ?? abort(404);
    }

    public function months(): int
    {
        return $this->filled('months') ? $this->integer('months') : 12;
    }
}
