<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every payment and refund paid in the period at the branches, oldest first,
 * as the payments list shows them. Refunds are negative, so the amounts add
 * up to what was taken; voided ones are listed with when they were voided and
 * don't count. See ADR 0008 and ADR 0024.
 */
final class PaymentsReport extends Report
{
    public function __construct(
        private readonly ReportPeriod $period,
        private readonly ?PaymentMethod $method = null,
        private readonly ?int $memberId = null,
    ) {}

    public function name(): string
    {
        return 'payments';
    }

    public function headings(): array
    {
        return [
            'Receipt', 'Paid on', 'Type', 'Member', 'Phone', 'Plan', 'Amount', 'Currency',
            'Method', 'Reference', 'Branch', 'Received by', 'Voided at', 'Void reason',
        ];
    }

    public function rows(): iterable
    {
        foreach ($this->payments()->lazy(self::CHUNK) as $payment) {
            $amount = $payment->type === PaymentType::Refund ? $payment->amount->negate() : $payment->amount;

            yield [
                (string) $payment->receipt_number,
                $this->day($payment->paid_on),
                $payment->type === PaymentType::Refund ? 'Refund' : 'Payment',
                (string) $payment->member?->full_name,
                (string) $payment->member?->phone,
                (string) $payment->membership?->plan?->name,
                $amount->toDecimal(),
                $amount->currency->value,
                $payment->method->label(),
                (string) $payment->reference,
                (string) $payment->branch?->name,
                (string) $payment->receivedBy?->user?->name,
                $this->moment($payment->voided_at),
                (string) $payment->void_reason,
            ];
        }
    }

    protected function gym(): Organization
    {
        return $this->period->gym;
    }

    protected function describeDays(): string
    {
        return $this->period->describe();
    }

    /**
     * @return Builder<Payment>
     */
    private function payments(): Builder
    {
        $branchIds = $this->period->branchIds;

        return Payment::query()
            ->where('paid_on', '>=', $this->period->from->toDateString())
            ->where('paid_on', '<=', $this->period->to->toDateString())
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds ?? []))
            ->when($this->method !== null, fn (Builder $query) => $query->where('method', $this->method))
            ->when($this->memberId !== null, fn (Builder $query) => $query->whereRelation('membership', 'member_id', $this->memberId))
            ->with(['member', 'membership.plan', 'branch', 'receivedBy.user'])
            ->orderBy('paid_on')
            ->orderBy('receipt_number');
    }
}
