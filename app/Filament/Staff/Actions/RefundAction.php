<?php

declare(strict_types=1);

namespace App\Filament\Staff\Actions;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Give money back on a cancelled membership, up to what was paid. Owners,
 * managers and accountants only.
 */
class RefundAction extends MoneyAction
{
    public static function getDefaultName(): ?string
    {
        return 'refund';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Refund')
            ->color('danger')
            ->visible(fn (Membership $record): bool => Gate::allows('refund', Payment::class) && $record->cancelled_at !== null);
    }

    protected function refusal(Membership $membership, Money $amount): ?PaymentRefused
    {
        return Payment::refusalToRefund($membership, $amount);
    }

    protected function recordMoney(Membership $membership, Money $amount, PaymentMethod $method, ?string $reference, Branch $branch, CarbonImmutable $paidOn, Staff $staff): Payment
    {
        return Payment::refund($membership, $amount, $method, $reference, $branch, $paidOn, $staff);
    }

    protected function suggestedAmount(Membership $membership): Money
    {
        return Payment::balanceOf($membership)->amountPaid;
    }

    protected function doneMessage(Payment $payment): string
    {
        return "{$payment->amount->format()} refunded, receipt {$payment->receipt_number}.";
    }
}
