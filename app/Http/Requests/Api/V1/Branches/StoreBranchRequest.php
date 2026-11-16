<?php

namespace App\Http\Requests\Api\V1\Branches;

use App\Domains\Organizations\Models\Branch;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Branch::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'bail', 'string', 'regex:'.Branch::CODE_PATTERN, $this->codeNoOtherBranchHas()],
            'address' => ['nullable', 'string', 'max:255'],
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
            'code.regex' => 'A code is 2 to 20 letters, digits or hyphens, such as BOLE.',
        ];
    }

    /**
     * Codes are compared as they are stored, however they were typed.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');

        if (is_string($code)) {
            $this->merge(['code' => Branch::tidyCode($code)]);
        }
    }

    /**
     * Each code once in a gym.
     */
    protected function codeNoOtherBranchHas(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $branch = $this->route('branch');

            if (Branch::codeTakenBy((string) $value, $branch instanceof Branch ? $branch : null) !== null) {
                $fail('Another branch of this gym already has this code.');
            }
        };
    }
}
