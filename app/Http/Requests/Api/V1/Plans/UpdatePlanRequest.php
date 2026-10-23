<?php

namespace App\Http\Requests\Api\V1\Plans;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Rules\ValidMoneyAmount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends StorePlanRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->plan());
    }

    /**
     * Only the fields that are sent are validated and changed. A new price
     * applies to future sales only; memberships already sold keep theirs.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'bail', 'string', 'max:255', $this->nameNoOtherPlanHas()],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['sometimes', new ValidMoneyAmount],
            'duration_count' => ['sometimes', 'integer', 'min:1', $this->longestDuration()],
            'duration_unit' => ['sometimes', Rule::enum(DurationUnit::class)],
            'freeze_days_allowed' => ['sometimes', 'integer', 'min:0', 'max:366'],
            ...$this->branchRules(),
        ];
    }

    /**
     * The unit that is sent, or else the plan's current unit.
     */
    protected function durationUnit(): ?DurationUnit
    {
        return parent::durationUnit() ?? $this->plan()->duration_unit;
    }

    private function plan(): Plan
    {
        return $this->route('plan');
    }
}
