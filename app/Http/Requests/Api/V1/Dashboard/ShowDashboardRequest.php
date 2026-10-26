<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowDashboardRequest extends FormRequest
{
    /**
     * The longest period the dashboard adds up, in days.
     */
    private const MOST_DAYS = 366;

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
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
        ];
    }

    /**
     * The period ends on or after it starts, and is at most a year long.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->to()->lessThan($this->from())) {
                    $validator->errors()->add('to', 'The period must end on or after the day it starts.');
                } elseif ($this->from()->diffInDays($this->to()) >= self::MOST_DAYS) {
                    $validator->errors()->add('to', 'The period can be at most a year long.');
                }
            },
        ];
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

    public function seesMoney(): bool
    {
        return $this->staff()->role->seesMoney();
    }

    /**
     * The branches the dashboard covers: the one asked for, or else the ones
     * the staff member works at. Null means every branch.
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

    private function staff(): Staff
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
