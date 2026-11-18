<?php

namespace App\Http\Requests\Api\V1\Organizations;

use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Language\Language;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationSettingsRequest extends FormRequest
{
    /**
     * Owners change the gym's settings.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', app(CurrentOrganization::class)->get());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message_language' => ['required', Rule::enum(Language::class)],
        ];
    }
}
