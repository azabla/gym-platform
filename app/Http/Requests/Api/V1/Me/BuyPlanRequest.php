<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\CurrentMember;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Exceptions\PromoCodeRefused;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BuyPlanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'promo_code' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * A plan can be bought in the app when the gym takes payments through
     * Chapa and the plan is valid at the member's home branch. A promo code
     * must be one the member can use for the plan today, and must leave
     * something to pay, since Chapa can't take nothing (ADR 0030).
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

                if (! ChapaAccount::query()->exists()) {
                    $validator->errors()->add('chapa_account', 'This gym doesn\'t take online payments yet.');
                } elseif (! Plan::query()->onSaleAt($this->member()->home_branch_id)->whereKey($this->plan()->id)->exists()) {
                    $validator->errors()->add('plan', 'This plan isn\'t sold at your branch. Ask the gym about it.');
                } elseif (($refusal = $this->promoCodeRefusal()) !== null) {
                    $validator->errors()->add($refusal->field, $refusal->getMessage());
                }
            },
        ];
    }

    /**
     * The promo code that was sent, if any.
     */
    public function promoCode(): ?PromoCode
    {
        return once(fn (): ?PromoCode => $this->filled('promo_code') ? PromoCode::findByCode($this->string('promo_code')->toString()) : null);
    }

    private function promoCodeRefusal(): ?PromoCodeRefused
    {
        if (! $this->filled('promo_code')) {
            return null;
        }

        $promoCode = $this->promoCode();

        if ($promoCode === null) {
            return PromoCodeRefused::unknown();
        }

        $refusal = $promoCode->refusalToUse($this->member(), $this->plan(), $this->member()->organization->today());

        if ($refusal !== null) {
            return $refusal;
        }

        return $promoCode->priceFor($this->plan())->isZero() ? PromoCodeRefused::makesItFree() : null;
    }

    public function member(): Member
    {
        return app(CurrentMember::class)->get();
    }

    /**
     * The gym's plan in the URL. Another gym's, or an archived one, isn't found.
     */
    public function plan(): Plan
    {
        return once(fn (): Plan => Plan::query()->findOrFail((int) $this->route('plan')));
    }

    /**
     * The member's open link to buy a plan, if they started buying one.
     */
    public function openPurchase(): ?PaymentLink
    {
        return once(fn (): ?PaymentLink => PaymentLink::query()
            ->whereBelongsTo($this->member())
            ->whereNotNull('plan_id')
            ->pending()
            ->first());
    }

    public function chapaAccount(): ChapaAccount
    {
        return ChapaAccount::query()->firstOrFail();
    }
}
