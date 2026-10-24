<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Domains\Notifications\Enums\NotificationTopic;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request: any of the topics,
     * each true or false (not "1" or "yes").
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $trueOrFalse = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail("The {$attribute} preference must be true or false.");
            }
        };

        $rules = [];

        foreach (NotificationTopic::cases() as $topic) {
            $rules[$topic->value] = ['sometimes', $trueOrFalse];
        }

        return $rules;
    }

    /**
     * Refuse topics that don't exist, so a typo isn't silently ignored.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $topics = array_map(fn (NotificationTopic $topic): string => $topic->value, NotificationTopic::cases());

                foreach (array_diff(array_keys($this->all()), $topics) as $unknown) {
                    $validator->errors()->add((string) $unknown, 'There is no such preference. Use membership, payments, visits or announcements.');
                }
            },
        ];
    }

    /**
     * The choices made, by topic.
     *
     * @return array<string, bool>
     */
    public function choices(): array
    {
        /** @var array<string, bool> */
        return $this->validated();
    }
}
