<?php

namespace App\Http\Requests\Api\V1\Members;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpdateMemberPhotoRequest extends FormRequest
{
    /**
     * Staff who may edit the member may change their photo.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('member'));
    }

    /**
     * A JPEG, PNG or WebP image of up to 5 MB (ADR 0036).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => self::photoRules(),
        ];
    }

    /**
     * The rules for an uploaded photo, shared with the members app.
     *
     * @return list<string>
     */
    public static function photoRules(): array
    {
        return ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120'];
    }

    /**
     * The uploaded image's bytes.
     */
    public function photoBytes(): string
    {
        /** @var UploadedFile $photo */
        $photo = $this->file('photo');

        return (string) $photo->get();
    }
}
