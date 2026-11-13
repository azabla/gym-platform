<?php

namespace App\Http\Requests\Api\V1\Reports;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class PaymentsReportRequest extends ReportRequest
{
    /**
     * Owners, managers and accountants: the roles that see the gym's money.
     */
    public function authorize(): bool
    {
        return $this->user()->can('export', Payment::class);
    }

    /**
     * The period and branch, and optionally a method or a member.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->periodRules(),
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'member_id' => ['nullable', 'integer'],
        ];
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return $this->filled('method') ? PaymentMethod::from($this->string('method')->toString()) : null;
    }

    public function memberId(): ?int
    {
        return $this->filled('member_id') ? $this->integer('member_id') : null;
    }
}
