<?php

namespace App\Http\Requests\Api\V1\CheckIns;

use App\Domains\Attendance\Models\CheckIn;
use App\Http\Requests\Api\V1\Concerns\AtABranchTheStaffMemberWorksAt;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScanCheckInRequest extends FormRequest
{
    use AtABranchTheStaffMemberWorksAt;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', CheckIn::class);
    }

    /**
     * Get the validation rules that apply to the request. Any text is a scan:
     * one that isn't a GQ1 code is denied, not refused, so it is recorded.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payload' => ['required', 'string', 'max:200'],
            'branch_id' => ['required', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
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
            'branch_id.required' => 'Choose the branch whose door this is.',
        ];
    }

    /**
     * The key the scanner sends to retry a scan safely, if any.
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? substr($key, 0, 100) : null;
    }

    protected function prepareForValidation(): void
    {
        $this->useTheOnlyBranchByDefault();
    }
}
