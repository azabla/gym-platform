<?php

namespace App\Http\Requests\Api\V1\Payments;

use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;

/**
 * A refund takes the same details as a payment: the amount, the method, its
 * transaction number, the branch and the day the money was given back.
 */
class StoreRefundRequest extends StorePaymentRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('refund', Payment::class);
    }

    /**
     * Why the refund can't be given, or null when it can: only a cancelled
     * membership is refunded, and never for more than was paid towards it.
     */
    protected function refusal(): ?PaymentRefused
    {
        return Payment::refusalToRefund($this->membership(), $this->amount());
    }
}
