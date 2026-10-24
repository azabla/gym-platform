<?php

namespace App\Http\Requests\Api\V1;

use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Rules\ValidPhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IssueSmsTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new ValidPhoneNumber],
            'code' => ['required', 'string', 'digits:6'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function phone(): PhoneNumber
    {
        return PhoneNumber::of($this->string('phone')->toString());
    }
}
