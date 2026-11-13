<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Http\Requests\Api\V1\Concerns\CoversAPeriodAtBranches;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowDashboardRequest extends FormRequest
{
    use CoversAPeriodAtBranches;

    /**
     * Every staff member of the gym sees the dashboard.
     */
    public function authorize(): bool
    {
        return $this->staff()->user()->is($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->periodRules();
    }

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

    public function seesMoney(): bool
    {
        return $this->staff()->role->seesMoney();
    }
}
