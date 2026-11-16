<?php

namespace App\Http\Requests\Api\V1\DiscountGroups;

use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignDiscountGroupRequest extends FormRequest
{
    /**
     * Owners and managers put members in a discount group (ADR 0032).
     */
    public function authorize(): bool
    {
        return $this->user()->can('assign', DiscountGroup::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'discount_group_id' => ['required', 'bail', 'integer', $this->groupOfThisGym()],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.app(CurrentOrganization::class)->get()->today()->toDateString()],
        ];
    }

    /**
     * Get the custom messages for the validator's rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'until.after_or_equal' => 'The last day can\'t be in the past.',
        ];
    }

    public function discountGroup(): DiscountGroup
    {
        return DiscountGroup::query()->findOrFail($this->integer('discount_group_id'));
    }

    public function until(): ?CarbonImmutable
    {
        return $this->filled('until') ? CarbonImmutable::parse($this->string('until')->toString()) : null;
    }

    /**
     * A group of this gym that hasn't been archived.
     */
    private function groupOfThisGym(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! DiscountGroup::query()->whereKey($value)->exists()) {
                $fail('Choose a discount group of this gym.');
            }
        };
    }
}
