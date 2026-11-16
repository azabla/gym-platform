<?php

namespace App\Http\Requests\Api\V1\Reports;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class CheckInsReportRequest extends ReportRequest
{
    /**
     * Every staff member, as they see the check-ins.
     */
    public function authorize(): bool
    {
        return $this->user()->can('export', CheckIn::class);
    }

    /**
     * The period and branch, and optionally only those let in or turned away.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->periodRules(),
            'result' => ['nullable', Rule::enum(CheckInResult::class)],
        ];
    }

    public function result(): ?CheckInResult
    {
        return $this->filled('result') ? CheckInResult::from($this->string('result')->toString()) : null;
    }
}
