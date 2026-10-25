<?php

namespace App\Http\Requests\Api\V1\Announcements;

use App\Domains\Announcements\Models\Announcement;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class UpdateAnnouncementRequest extends StoreAnnouncementRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->announcement());
    }

    /**
     * Only the fields that are sent are validated and changed. A scheduled
     * announcement can be moved to another time in the future, not to now.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:80'],
            'body' => ['sometimes', 'string', 'max:1000'],
            ...array_map(fn (array $rules): array => ['sometimes', ...$rules], $this->audienceRules()),
            'send_at' => ['sometimes', 'date', 'after:now'],
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
            'send_at.after' => 'Choose a time in the future.',
        ];
    }

    /**
     * Only an announcement that hasn't gone out can be changed.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->announcement()->isScheduled()) {
                    $validator->errors()->add('announcement', 'Only an announcement that hasn\'t gone out can be changed.');
                }
            },
        ];
    }

    /**
     * The changes, as the announcement keeps them.
     *
     * @return array{title?: string, body?: string, branch_id?: int|null, include_recently_expired?: bool, send_at?: CarbonImmutable}
     */
    public function changes(): array
    {
        $changes = [];

        foreach (['title', 'body'] as $field) {
            if ($this->has($field)) {
                $changes[$field] = $this->string($field)->toString();
            }
        }

        if ($this->has('branch_id')) {
            $changes['branch_id'] = $this->chosenBranch()?->id;
        }

        if ($this->has('include_recently_expired')) {
            $changes['include_recently_expired'] = $this->boolean('include_recently_expired');
        }

        if ($this->has('send_at')) {
            $changes['send_at'] = CarbonImmutable::parse($this->string('send_at')->toString());
        }

        return $changes;
    }

    private function announcement(): Announcement
    {
        return $this->route('announcement');
    }
}
