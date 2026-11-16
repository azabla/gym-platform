<?php

namespace App\Http\Requests\Api\V1\PromoCodes;

use App\Domains\Memberships\Models\PromoCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdatePromoCodeRequest extends StorePromoCodeRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->promoCode());
    }

    /**
     * Only the fields that are sent are validated and changed. Memberships
     * already sold with the code keep their price.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'bail', 'string', 'regex:'.PromoCode::PATTERN, $this->codeNoOtherHas(), $this->notRenamedOnceUsed()],
            'discount' => ['sometimes', 'bail', 'string', 'max:20', $this->readableDiscount()],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', $this->notAfterTheLastDay()],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', $this->notBeforeTheFirstDay()],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MOST_USES],
            'once_per_member' => ['sometimes', 'boolean'],
            ...$this->planRules(),
        ];
    }

    /**
     * The code keeps its plans unless new ones are sent, and the policy has
     * already checked the staff member manages its current plans.
     */
    protected function checksPlans(): bool
    {
        return $this->has('plan_ids');
    }

    /**
     * A code printed on posters and already used keeps its text, so members
     * and staff can still find it.
     */
    private function notRenamedOnceUsed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $promoCode = $this->promoCode();

            if (PromoCode::tidy((string) $value) !== $promoCode->code && $promoCode->usesCount() > 0) {
                $fail('This code has been used, so its text can\'t change. Archive it and make a new one.');
            }
        };
    }

    /**
     * The last day sent can't be before the first day, sent or kept.
     */
    private function notBeforeTheFirstDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $startsOn = $this->has('starts_on') ? $this->input('starts_on') : $this->promoCode()->starts_on?->toDateString();

            if (is_string($value) && is_string($startsOn) && $value < $startsOn) {
                $fail('The last day can\'t be before the first day.');
            }
        };
    }

    /**
     * The first day sent can't be after the last day kept; a last day sent
     * is checked against it by notBeforeTheFirstDay().
     */
    private function notAfterTheLastDay(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $endsOn = $this->promoCode()->ends_on?->toDateString();

            if (! $this->has('ends_on') && is_string($value) && $endsOn !== null && $value > $endsOn) {
                $fail('The first day can\'t be after the last day.');
            }
        };
    }

    private function promoCode(): PromoCode
    {
        return $this->route('promo_code');
    }
}
