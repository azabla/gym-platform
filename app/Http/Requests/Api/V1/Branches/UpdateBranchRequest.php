<?php

namespace App\Http\Requests\Api\V1\Branches;

use App\Domains\Organizations\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateBranchRequest extends StoreBranchRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->branch());
    }

    /**
     * Only the fields that are sent are validated and changed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'bail', 'string', 'regex:'.Branch::CODE_PATTERN, $this->codeNoOtherBranchHas()],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function branch(): Branch
    {
        return $this->route('branch');
    }
}
