<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Domains\Members\CurrentMember;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * End a freeze of one of the signed-in member's memberships early, when they
 * are back sooner: one still to come or going on today, whoever made it, as
 * at the desk (ADR 0026).
 */
class EndMyFreezeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The freeze must still be going on or to come, as
     * Membership::refusalToEndFreeze() says.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $refusal = $this->membership()->refusalToEndFreeze($this->freeze(), $this->today());

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
     * A freeze of that membership, or a 404.
     */
    public function freeze(): MembershipFreeze
    {
        return once(fn (): MembershipFreeze => $this->membership()->freezes()->findOrFail((int) $this->route('freeze')));
    }

    public function today(): CarbonImmutable
    {
        return app(CurrentOrganization::class)->get()->today();
    }
}
