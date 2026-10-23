<?php

namespace App\Http\Requests\Api\V1\Plans;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
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
        ];
    }

    /**
     * The validated details, with the price as Money.
     *
     * @return array<string, mixed>
     */
    public function planDetails(): array
    {
        $details = $this->validated();

        if (array_key_exists('price', $details)) {
            $details['price'] = Money::of((string) $details['price']);
        }

        return $details;
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
