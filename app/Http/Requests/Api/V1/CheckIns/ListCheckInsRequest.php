<?php

namespace App\Http\Requests\Api\V1\CheckIns;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCheckInsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', CheckIn::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'on' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
            'result' => ['nullable', Rule::enum(CheckInResult::class)],
            'member_id' => ['nullable', 'integer'],
        ];
    }
}
