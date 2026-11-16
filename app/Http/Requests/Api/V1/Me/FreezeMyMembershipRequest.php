<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Domains\Members\CurrentMember;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Freeze one of the signed-in member's memberships, from today or a later
 * day, within the freeze days its plan left them: the same rules as at the
 * desk, except that members never date a freeze in the past or go beyond
 * their freeze days (ADR 0026).
 */
class FreezeMyMembershipRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The freeze must fit the membership, as Membership::refusalToFreeze()
     * says.
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

                $refusal = $this->membership()->refusalToFreeze($this->startsOn(), $this->endsOn(), false, $this->today());

                if ($refusal !== null) {
                    $validator->errors()->add($refusal->field, $refusal->getMessage());
                }
            },
        ];
    }

    /**
     * The member's own membership at this gym, or a 404.
     */
    public function membership(): Membership
    {
        return once(fn (): Membership => Membership::query()
            ->whereBelongsTo(app(CurrentMember::class)->get())
            ->findOrFail((int) $this->route('membership')));
    }

    /**
     * The first day of the freeze: the one sent, or else today where the gym is.
     */
    public function startsOn(): CarbonImmutable
    {
        return $this->filled('starts_on') ? CarbonImmutable::parse($this->string('starts_on')->toString()) : $this->today();
    }

    public function endsOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('ends_on')->toString());
    }

    /**
     * Why, in the member's words, or a note that they froze it in the app.
     */
    public function reason(): string
    {
        return $this->filled('reason') ? $this->string('reason')->trim()->toString() : 'Frozen by the member in the app.';
    }

    private function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
