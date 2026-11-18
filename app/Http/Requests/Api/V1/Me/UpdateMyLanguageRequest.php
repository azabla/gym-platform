<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Domains\Shared\Language\Language;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMyLanguageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'language' => ['required', Rule::enum(Language::class)],
        ];
    }

    public function language(): Language
    {
        return Language::from($this->string('language')->toString());
    }
}
