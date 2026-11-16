<?php

namespace App\Http\Requests\Api\V1\PromoCodes;

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePromoCodeRequest extends FormRequest
{
    protected const CHOOSE_YOUR_PLANS = 'Choose plans sold only at branches you work at.';

    /** The most uses a code can be limited to. */
    public const MOST_USES = 100_000;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', PromoCode::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'bail', 'string', 'regex:'.PromoCode::PATTERN, $this->codeNoOtherHas()],
            'discount' => ['required', 'bail', 'string', 'max:20', $this->readableDiscount()],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:'.self::MOST_USES],
            'once_per_member' => ['sometimes', 'boolean'],
            ...$this->planRules(),
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
            'code.regex' => 'A code is 3 to 30 letters, digits or dashes, like NEWYEAR or GYM-2027.',
            'ends_on.after_or_equal' => 'The last day can\'t be before the first day.',
        ];
    }

    /**
     * A manager limited to some branches makes codes only for plans sold
     * only at their branches, never for every plan (ADR 0030).
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->checksPlans()) {
                    return;
                }

                if (! $this->user()->can('createFor', [PromoCode::class, $this->chosenPlanIds()])) {
                    $validator->errors()->add('plan_ids', self::CHOOSE_YOUR_PLANS);
                }
            },
        ];
    }

    /**
     * The validated details, with the code and discount tidied. The plans are
     * applied separately, see chosenPlans().
     *
     * @return array<string, mixed>
     */
    public function codeDetails(): array
    {
        $details = $this->safe()->except('plan_ids');

        if (array_key_exists('discount', $details)) {
            $details['discount'] = trim((string) $details['discount']);
        }

        return $details;
    }

    /**
     * Whether the request says which plans the code is for.
     */
    public function choosesPlans(): bool
    {
        return $this->has('plan_ids');
    }

    /**
     * The plans the code is limited to, or null for every plan.
     *
     * @return array<int, Plan>|null
     */
    public function chosenPlans(): ?array
    {
        $planIds = $this->chosenPlanIds();

        return $planIds === null ? null : Plan::query()->findMany($planIds)->all();
    }

    /**
     * Whether the plans the code would be for need checking: a new code
     * always does, being for every plan unless plans are chosen.
     */
    protected function checksPlans(): bool
    {
        return true;
    }

    /**
     * @return list<int>|null
     */
    protected function chosenPlanIds(): ?array
    {
        $planIds = $this->input('plan_ids');

        return is_array($planIds) ? array_values(array_map('intval', $planIds)) : null;
    }

    /**
     * plan_ids is null for every plan, or a list of plans of this gym that
     * are still on sale.
     *
     * @return array<string, array<mixed>>
     */
    protected function planRules(): array
    {
        return [
            'plan_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'plan_ids.*' => ['bail', 'integer', 'distinct', $this->planOnSale()],
        ];
    }

    /**
     * Codes are unique within a gym, ignoring capitals, archived ones included.
     */
    protected function codeNoOtherHas(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $promoCode = $this->route('promo_code');

            if (PromoCode::codeTakenBy((string) $value, $promoCode instanceof PromoCode ? $promoCode : null) !== null) {
                $fail('This gym already has this code, perhaps archived. Choose another.');
            }
        };
    }

    /**
     * A discount staff could type at the desk: "500" off or "10%" off.
     */
    protected function readableDiscount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                Discount::parse((string) $value, 'Promo code');
            } catch (InvalidDiscount $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    private function planOnSale(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! Plan::query()->whereKey($value)->exists()) {
                $fail('Choose plans of this gym that are still on sale.');
            }
        };
    }
}
