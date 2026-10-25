<?php

namespace App\Http\Requests\Api\V1\Announcements;

use App\Domains\Announcements\Models\Announcement;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelAnnouncementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->announcement());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Only an announcement that hasn't gone out can be cancelled.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->announcement()->isScheduled()) {
                    $validator->errors()->add('announcement', 'Only an announcement that hasn\'t gone out can be cancelled.');
                }
            },
        ];
    }

    private function announcement(): Announcement
    {
        return $this->route('announcement');
    }
}
