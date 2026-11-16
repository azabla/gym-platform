<?php

namespace App\Http\Requests\Api\V1\DiscountGroups;

use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Discount\Exceptions\InvalidDiscount;
use App\Domains\Memberships\Models\DiscountGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDiscountGroupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', DiscountGroup::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'bail', 'string', 'max:100', $this->nameNoOtherGroupHas()],
            'discount' => ['required', 'bail', 'string', 'max:20', $this->readableDiscount()],
        ];
    }

    /**
     * The validated details, tidied.
     *
     * @return array{name?: string, discount?: string}
     */
    public function groupDetails(): array
    {
        return array_map(fn (mixed $value): string => trim((string) $value), $this->safe()->only(['name', 'discount']));
    }

    /**
     * Group names are unique within a gym, ignoring capitals. Archived groups don't count.
     */
    protected function nameNoOtherGroupHas(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $group = $this->route('discount_group');

            if (DiscountGroup::nameTakenBy((string) $value, $group instanceof DiscountGroup ? $group : null) !== null) {
                $fail('Another discount group of this gym already has this name.');
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
                Discount::parse((string) $value, 'Discount group');
            } catch (InvalidDiscount $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
