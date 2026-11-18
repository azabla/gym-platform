<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Domains\Notifications\Enums\PushApp;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePushTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. The language is
     * a code such as "am" or "en", optionally with a region ("en-US").
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['sometimes', 'string', 'in:fcm'],
            'token' => ['required', 'string', 'max:512'],
            'locale' => ['nullable', 'string', 'regex:/^[a-z]{2,3}([-_][A-Za-z]{2})?$/'],
            'app' => ['sometimes', Rule::enum(PushApp::class)],
        ];
    }

    /**
     * Which app the phone's push token is for: the members app unless the
     * staff app says so (ADR 0035).
     */
    public function app(): PushApp
    {
        return $this->enum('app', PushApp::class) ?? PushApp::Members;
    }

    /**
     * The language, as Laravel names its language files: "am", "en".
     */
    public function language(): ?string
    {
        return $this->filled('locale') ? strtolower(substr($this->string('locale')->toString(), 0, 2)) : null;
    }
}
