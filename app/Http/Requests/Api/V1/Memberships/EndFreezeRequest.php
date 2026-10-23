<?php

namespace App\Http\Requests\Api\V1\Memberships;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class EndFreezeRequest extends FormRequest
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
        return [];
    }

    /**
     * Only a freeze that is still to come or going on today can end early.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->membership()->cancelled_at !== null) {
                    $validator->errors()->add('membership', 'This membership is cancelled.');
                } elseif ($this->freeze()->ends_on->isBefore(app(CurrentOrganization::class)->get()->today())) {
                    $validator->errors()->add('freeze', 'This freeze has already ended.');
                }
            },
        ];
    }

    private function membership(): Membership
    {
        return $this->route('membership');
    }

    private function freeze(): MembershipFreeze
    {
        return $this->route('freeze');
    }
}
