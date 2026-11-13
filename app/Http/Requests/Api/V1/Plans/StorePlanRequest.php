<?php

namespace App\Http\Requests\Api\V1\Plans;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Rules\ValidMoneyAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePlanRequest extends FormRequest
{
    protected const CHOOSE_YOUR_BRANCHES = 'Choose branches you work at.';

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
            'freeze_days_allowed' => ['sometimes', 'integer', 'min:0', 'max:'.Plan::MOST_FREEZE_DAYS],
            'visit_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.Plan::MOST_VISITS],
            ...$this->branchRules(),
        ];
    }

    /**
     * Staff limited to some branches can't make a plan valid at every branch
     * (ADR 0023).
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->meansAllBranches() && ! $this->staff()->works_at_all_branches) {
                    $validator->errors()->add('branch_ids', self::CHOOSE_YOUR_BRANCHES);
                }
            },
        ];
    }

    /**
     * Whether the plan would be valid at every branch: a new plan is, unless
     * branches are chosen.
     */
    protected function meansAllBranches(): bool
    {
        return $this->input('branch_ids') === null;
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
     * Staff limited to some branches choose among those, and can't make a
     * plan valid at every branch (ADR 0023).
     *
     * @return array<string, array<mixed>>
     */
    protected function branchRules(): array
    {
        return [
            'branch_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'branch_ids.*' => ['bail', 'integer', 'distinct', $this->branchOfThisGym(), $this->branchTheStaffMemberWorksAt()],
        ];
    }

    /**
     * Plan names are unique within a gym, ignoring capitals. Archived plans don't count.
     */
    protected function nameNoOtherPlanHas(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $plan = $this->route('plan');

            if (Plan::nameTakenBy((string) $value, $plan instanceof Plan ? $plan : null) !== null) {
                $fail('Another plan of this gym already has this name.');
            }
        };
    }

    protected function staff(): Staff
    {
        return app(CurrentStaff::class)->get();
    }

    private function branchOfThisGym(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! Branch::query()->whereKey($value)->exists()) {
                $fail('Choose branches of this gym.');
            }
        };
    }

    private function branchTheStaffMemberWorksAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = is_numeric($value) ? Branch::query()->find((int) $value) : null;

            if ($branch !== null && ! $this->staff()->worksAt($branch)) {
                $fail(self::CHOOSE_YOUR_BRANCHES);
            }
        };
    }

    /**
     * Up to 366 days, or up to 36 months.
     */
    protected function longestDuration(): string
    {
        return 'max:'.Plan::longestDurationIn($this->durationUnit() ?? DurationUnit::Day);
    }

    protected function durationUnit(): ?DurationUnit
    {
        $unit = $this->input('duration_unit');

        return is_string($unit) ? DurationUnit::tryFrom($unit) : null;
    }
}
