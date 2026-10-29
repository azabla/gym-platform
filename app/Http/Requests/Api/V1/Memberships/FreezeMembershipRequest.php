<?php

namespace App\Http\Requests\Api\V1\Memberships;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FreezeMembershipRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('freeze', $this->membership());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['nullable', 'bail', 'date_format:Y-m-d', $this->notInThePastUnlessAllowed()],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * The freeze must fit the membership: start while it runs, not overlap
     * another freeze, and stay within the freeze days its plan allowed unless
     * an owner or manager says otherwise.
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

                $refusal = $this->membership()->refusalToFreeze(
                    $this->startsOn(),
                    $this->endsOn(),
                    $this->user()->can('freezeBeyondAllowance', Membership::class),
                );

                if ($refusal !== null) {
                    $validator->errors()->add($refusal->field, $refusal->getMessage());
                }
            },
        ];
    }

    /**
     * The first day of the freeze: the one sent, or else today.
     */
    public function startsOn(): CarbonImmutable
    {
        if ($this->filled('starts_on')) {
            return CarbonImmutable::parse($this->string('starts_on')->toString());
        }

        return $this->today();
    }

    public function endsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_on')->toString());
    }

    private function notInThePastUnlessAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $startsInThePast = CarbonImmutable::parse((string) $value)->lessThan($this->today());

            if ($startsInThePast && ! $this->user()->can('backdate', Membership::class)) {
                $fail('Only owners and managers can start a freeze on a past date.');
            }
        };
    }

    private function membership(): Membership
    {
        return $this->route('membership');
    }

    private function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
