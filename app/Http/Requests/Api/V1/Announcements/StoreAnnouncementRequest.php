<?php

namespace App\Http\Requests\Api\V1\Announcements;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Models\Branch;
use App\Http\Requests\Api\V1\Concerns\AtABranchTheStaffMemberWorksAt;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAnnouncementRequest extends FormRequest
{
    use AtABranchTheStaffMemberWorksAt;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Announcement::class);
    }

    /**
     * Get the validation rules that apply to the request. Without send_at it
     * goes now; without branch_id it goes to every branch.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:1000'],
            ...$this->audienceRules(),
            'send_at' => ['nullable', 'date', 'after:now'],
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
            'send_at.after' => 'Choose a time in the future, or leave it out to send it now.',
        ];
    }

    /**
     * The branch whose members it goes to, or null for every branch.
     */
    public function chosenBranch(): ?Branch
    {
        return $this->filled('branch_id') ? $this->branch() : null;
    }

    /**
     * When it goes, or null for now.
     */
    public function sendAt(): ?CarbonImmutable
    {
        return $this->filled('send_at') ? CarbonImmutable::parse($this->string('send_at')->toString()) : null;
    }

    /**
     * Who it goes to. Staff limited to some branches speak only to one of
     * their branches, never to the whole gym.
     *
     * @return array<string, array<mixed>>
     */
    protected function audienceRules(): array
    {
        return [
            'branch_id' => [$this->staff()->works_at_all_branches ? 'nullable' : 'required', 'bail', 'integer', $this->branchTheStaffMemberWorksAt()],
            'include_recently_expired' => ['sometimes', $this->trueOrFalse()],
        ];
    }

    /**
     * True or false, not "1" or "yes".
     */
    protected function trueOrFalse(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail('The :attribute field must be true or false.');
            }
        };
    }
}
