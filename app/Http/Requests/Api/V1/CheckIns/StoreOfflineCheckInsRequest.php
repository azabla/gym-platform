<?php

namespace App\Http\Requests\Api\V1\CheckIns;

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfflineCheckInsRequest extends FormRequest
{
    /**
     * Staff who may scan send what they scanned offline.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', CheckIn::class);
    }

    /**
     * Up to 200 scans, each with the app's own id for it, the QR text, the
     * branch, when it was scanned (with its offset), and what the door
     * decided, with the reason when it turned the member away.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scans' => ['required', 'array', 'min:1', 'max:200'],
            'scans.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'scans.*.payload' => ['required', 'string', 'max:200'],
            'scans.*.branch_id' => ['required', 'integer'],
            'scans.*.scanned_at' => ['required', 'date'],
            'scans.*.decision' => ['required', 'in:allow,deny'],
            'scans.*.reason_code' => ['nullable', 'required_if:scans.*.decision,deny', 'prohibited_if:scans.*.decision,allow', Rule::enum(CheckInDenial::class)],
        ];
    }
}
