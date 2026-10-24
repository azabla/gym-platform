<?php

namespace App\Http\Requests\Api\V1\Branches;

use App\Domains\Organizations\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOpeningHoursRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->branch());
    }

    /**
     * The whole week at once: each open weekday once, 1 for Monday to 7 for
     * Sunday, opening before closing on the same day. An empty list makes
     * the branch always open.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hours' => ['present', 'array', 'max:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i', 'after:hours.*.opens_at'],
        ];
    }

    /**
     * Get the custom messages for the validator's rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hours.*.weekday.distinct' => 'Give each weekday once.',
            'hours.*.closes_at.after' => 'Closing must be after opening, on the same day.',
        ];
    }

    /**
     * The week, as the branch keeps it.
     *
     * @return list<array{weekday: int, opens_at: string, closes_at: string}>
     */
    public function week(): array
    {
        /** @var list<array{weekday: int|string, opens_at: string, closes_at: string}> $hours */
        $hours = $this->validated('hours');

        return array_values(array_map(fn (array $day): array => [
            'weekday' => (int) $day['weekday'],
            'opens_at' => "{$day['opens_at']}:00",
            'closes_at' => "{$day['closes_at']}:00",
        ], $hours));
    }

    private function branch(): Branch
    {
        return $this->route('branch');
    }
}
