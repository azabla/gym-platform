<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Http\Resources\V1\BranchResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the signed-in member's receipts, a payment or a refund, for the
 * members app. It shows whether the receipt was voided, but not why, nor
 * which staff member took or voided the money.
 *
 * @mixin Payment
 */
class MyPaymentResource extends JsonResource
{
    /**
     * The relations the response shows.
     */
    public const RELATIONS = ['branch', 'membership.plan'];

    /**
     * Transform the resource into an array, with the relations above loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'type' => $this->type,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'paid_on' => $this->paid_on->toDateString(),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'membership' => $this->whenLoaded('membership', fn (Membership $membership) => [
                'id' => $membership->id,
                'plan' => [
                    'id' => $membership->plan->id,
                    'name' => $membership->plan->name,
                ],
            ]),
            'voided' => $this->voided_at !== null,
        ];
    }
}
