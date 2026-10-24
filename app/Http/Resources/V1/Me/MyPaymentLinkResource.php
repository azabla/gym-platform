<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment link of the signed-in member, for the members app: what it asks
 * for, where it stands, and its receipt once paid. The checkout URL is shown
 * only while the link is open.
 *
 * @mixin PaymentLink
 */
class MyPaymentLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'amount' => $this->amount,
            'checkout_url' => $this->status === PaymentLinkStatus::Pending ? $this->checkout_url : null,
            'membership_id' => $this->membership_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'payment' => $this->whenLoaded('payment', fn (Payment $payment) => [
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'paid_on' => $payment->paid_on->toDateString(),
            ]),
        ];
    }
}
