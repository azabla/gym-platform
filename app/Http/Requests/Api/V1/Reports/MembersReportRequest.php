<?php

namespace App\Http\Requests\Api\V1\Reports;

use App\Domains\Members\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The members as they are today, so no period: only a branch and a search.
 */
class MembersReportRequest extends ReportRequest
{
    /**
     * Owners and managers: the file has every member's phone number.
     */
    public function authorize(): bool
    {
        return $this->user()->can('export', Member::class);
    }

    /**
     * Optionally one branch the staff member works at, and part of a name or
     * the digits of a phone number, as the members list's ?search= reads it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => $this->periodRules()['branch_id'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function search(): ?string
    {
        return $this->filled('search') ? $this->string('search')->trim()->toString() : null;
    }
}
