<?php

namespace App\Http\Requests\Api\V1\Memberships;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMembershipRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Membership::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'bail', 'integer', $this->planOnSale()],
            'starts_on' => ['nullable', 'bail', 'date_format:Y-m-d', $this->notInThePastUnlessAllowed()],
            'discount' => ['nullable', 'bail', 'string', $this->discountOnlyFromOwnersAndManagers(), $this->discountThatFitsThePrice()],
            'discount_reason' => ['nullable', 'string', 'max:500', 'required_with:discount'],
        ];
    }

    /**
     * The new membership must fit around the member's other memberships.
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

                $startsOn = $this->startsOn();
                $clash = Membership::query()
                    ->whereBelongsTo($this->member())
                    ->notCancelled()
                    ->overlapping($startsOn, $this->plan()->endsOnFor($startsOn))
                    ->orderBy('starts_on')
                    ->first();

                if ($clash !== null) {
                    $validator->errors()->add('starts_on', sprintf(
                        'The member already has a membership from %s to %s. The first free start date is %s.',
                        $clash->starts_on->format('j F Y'),
                        $clash->ends_on->format('j F Y'),
                        Membership::firstFreeDayFor($this->member(), $this->today())->format('j F Y'),
                    ));
                }
            },
        ];
    }

    public function plan(): Plan
    {
        return Plan::query()->findOrFail($this->integer('plan_id'));
    }

    /**
     * The start date that was sent, or else the first free day for the member.
     */
    public function startsOn(): CarbonImmutable
    {
        if ($this->filled('starts_on')) {
            return CarbonImmutable::parse($this->string('starts_on')->toString());
        }

        return Membership::firstFreeDayFor($this->member(), $this->today());
    }

    /**
     * The discount that was sent, if any: "500" off or "10%" off, with its reason.
     */
    public function discount(): ?Discount
    {
        if (! $this->filled('discount')) {
            return null;
        }

        return Discount::parse(
            $this->string('discount')->toString(),
            $this->string('discount_reason')->toString(),
            $this->plan()->price->currency,
        );
    }

    private function discountOnlyFromOwnersAndManagers(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->user()->can('discount', Membership::class)) {
                $fail('Only owners and managers can give a discount.');
            }
        };
    }

    /**
     * The discount must read as an amount or a percentage, and take something,
     * but not more than everything, off the plan's price.
     */
    private function discountThatFitsThePrice(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $plan = Plan::query()->find($this->integer('plan_id'));

            if ($plan === null) {
                return;
            }

            try {
                Discount::parse((string) $value, $this->string('discount_reason')->toString(), $plan->price->currency)
                    ->appliedTo($plan->price);
            } catch (InvalidDiscount $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    /**
     * A plan of this gym that hasn't been archived.
     */
    private function planOnSale(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! Plan::query()->whereKey($value)->exists()) {
                $fail('Choose a plan of this gym that is still on sale.');
            }
        };
    }

    private function notInThePastUnlessAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $startsInThePast = CarbonImmutable::parse((string) $value)->lessThan($this->today());

            if ($startsInThePast && ! $this->user()->can('backdate', Membership::class)) {
                $fail('Only owners and managers can start a membership on a past date.');
            }
        };
    }

    private function member(): Member
    {
        return $this->route('member');
    }

    private function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
