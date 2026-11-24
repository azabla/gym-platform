<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Api\V1\Members\UpdateMemberPhotoRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpdateMyPhotoRequest extends FormRequest
{
    /**
     * Every signed-in member chooses their own photo.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The same photo the desk may take: a JPEG, PNG or WebP image of up to
     * 5 MB (ADR 0036).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => UpdateMemberPhotoRequest::photoRules(),
        ];
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
