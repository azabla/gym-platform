<?php

namespace App\Http\Requests\Api\V1\Plans;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Shared\Money\Money;
use App\Rules\ValidMoneyAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Plan::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'bail', 'string', 'max:255', $this->nameNoOtherPlanHas()],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', new ValidMoneyAmount],
            'duration_count' => ['required', 'integer', 'min:1', $this->longestDuration()],
            'duration_unit' => ['required', Rule::enum(DurationUnit::class)],
            'freeze_days_allowed' => ['sometimes', 'integer', 'min:0', 'max:366'],
            'visit_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            ...$this->branchRules(),
        ];
    }

    /**
     * The validated details, with the price as Money. The branches are applied
     * separately, see chosenBranches().
     *
     * @return array<string, mixed>
     */
    public function planDetails(): array
    {
        $details = $this->safe()->except('branch_ids');

        if (array_key_exists('price', $details)) {
            $details['price'] = Money::of((string) $details['price']);
        }

        return $details;
    }

    /**
     * Whether the request says where the plan is valid.
     */
    public function choosesBranches(): bool
    {
        return $this->has('branch_ids');
    }

    /**
     * The branches the plan is limited to, or null for all branches.
     *
     * @return array<int, Branch>|null
     */
    public function chosenBranches(): ?array
    {
        $branchIds = $this->validated('branch_ids');

        return $branchIds === null ? null : Branch::query()->findMany($branchIds)->all();
    }

    /**
     * branch_ids is null for all branches, or a list of branches of this gym.
     *
     * @return array<string, array<mixed>>
     */
    protected function branchRules(): array
    {
        return [
            'branch_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'branch_ids.*' => ['bail', 'integer', 'distinct', $this->branchOfThisGym()],
        ];
    }

    /**
     * Plan names are unique within a gym, ignoring capitals. Archived plans don't count.
     */
    protected function nameNoOtherPlanHas(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $nameIsTaken = Plan::query()
                ->whereRaw('lower(name) = lower(?)', [$value])
                ->when($this->route('plan'), fn ($query, Plan $plan) => $query->whereKeyNot($plan->getKey()))
                ->exists();

            if ($nameIsTaken) {
                $fail('Another plan of this gym already has this name.');
            }
        };
    }

    private function branchOfThisGym(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! Branch::query()->whereKey($value)->exists()) {
                $fail('Choose branches of this gym.');
            }
        };
    }

    /**
     * Up to 366 days, or up to 36 months.
     */
    protected function longestDuration(): string
    {
        return $this->durationUnit() === DurationUnit::Month ? 'max:36' : 'max:366';
    }

    protected function durationUnit(): ?DurationUnit
    {
        $unit = $this->input('duration_unit');

        return is_string($unit) ? DurationUnit::tryFrom($unit) : null;
    }
}
