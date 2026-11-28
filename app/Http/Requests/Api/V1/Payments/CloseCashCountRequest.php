<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Models\CashCount;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Rules\ValidMoneyAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing the staff member's own cash count at a branch they work at (ADR 0046).
 */
class CloseCashCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CashCount::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
            'counted' => ['required', new ValidMoneyAmount],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function branch(): Branch
    {
        return Branch::query()->findOrFail($this->integer('branch_id'));
    }

    private function branchTheStaffMemberWorksAt(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = Branch::query()->find((int) $value);

            if ($branch === null || ! app(CurrentStaff::class)->get()->worksAt($branch)) {
                $fail(__('Choose a branch you work at.'));
            }
        };
    }
}
