<?php

namespace App\Http\Requests\Api\V1\DiscountGroups;

use App\Domains\Memberships\Models\DiscountGroup;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateDiscountGroupRequest extends StoreDiscountGroupRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->discountGroup());
    }

    /**
     * Only the fields that are sent are validated and changed. Memberships
     * already sold at the group's price keep it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'bail', 'string', 'max:100', $this->nameNoOtherGroupHas()],
            'discount' => ['sometimes', 'bail', 'string', 'max:20', $this->readableDiscount()],
        ];
    }

    private function discountGroup(): DiscountGroup
    {
        return $this->route('discount_group');
    }
}
