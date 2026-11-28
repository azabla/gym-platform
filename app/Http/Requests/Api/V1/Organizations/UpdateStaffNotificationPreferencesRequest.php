<?php

namespace App\Http\Requests\Api\V1\Organizations;

use App\Domains\Notifications\Enums\StaffNotificationTopic;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStaffNotificationPreferencesRequest extends FormRequest
{
    /**
     * Every staff member chooses their own.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request: any of the topics,
     * each with push, sms or both, true or false (not "1" or "yes").
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $trueOrFalse = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail("The {$attribute} choice must be true or false.");
            }
        };

        $rules = [];

        foreach (StaffNotificationTopic::chosenByStaff() as $topic) {
            $rules[$topic->value] = ['sometimes', 'array:push,sms'];
            $rules["{$topic->value}.push"] = ['sometimes', $trueOrFalse];
            $rules["{$topic->value}.sms"] = ['sometimes', $trueOrFalse];
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
                $topics = array_map(fn (StaffNotificationTopic $topic): string => $topic->value, StaffNotificationTopic::chosenByStaff());

                foreach (array_diff(array_keys($this->all()), $topics) as $unknown) {
                    $validator->errors()->add((string) $unknown, 'There is no such topic. Use online_payments, refunds, app_freezes or members_to_call.');
                }
            },
        ];
    }

    /**
     * The choices made, by topic.
     *
     * @return array<string, array{push?: bool, sms?: bool}>
     */
    public function choices(): array
    {
        /** @var array<string, array{push?: bool, sms?: bool}> */
        return $this->validated();
    }
}
