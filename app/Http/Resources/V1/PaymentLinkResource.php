<?php

namespace App\Http\Resources\V1;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment link: what it asks for, for which membership, and where it
 * stands. The checkout URL is shown only while the link is open, so a paid
 * or cancelled link isn't shared again.
 *
 * @mixin PaymentLink
 */
class PaymentLinkResource extends JsonResource
{
    /**
     * The relations a payment link response shows. The membership's freezes
     * give its status without a query per link.
     */
    private const RELATIONS = ['branch', 'membership.plan', 'membership.member', 'membership.freezes', 'createdBy.user', 'cancelledBy.user', 'payment'];

    /**
     * Load everything a payment link response shows: the relations above and
     * the balance of the membership as it is now.
     */
    public static function loadDetails(PaymentLink ...$paymentLinks): void
    {
        (new Collection($paymentLinks))->load(self::RELATIONS);

        MembershipResource::attachBalances(
            ...array_map(fn (PaymentLink $paymentLink): Membership => $paymentLink->membership, $paymentLinks),
        );
    }

    /**
     * Transform the resource into an array, once loadDetails() ran.
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
            'tx_ref' => $this->tx_ref,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'member' => $this->whenLoaded('membership', fn (Membership $membership) => [
                'id' => $membership->member->id,
                'full_name' => $membership->member->full_name,
                'phone' => $membership->member->phone,
            ]),
            'membership' => new MembershipResource($this->whenLoaded('membership')),
            'created_by' => $this->whenLoaded('createdBy', fn (Staff $staff) => $this->staffMember($staff)),
            'created_at' => $this->created_at?->toIso8601String(),
            'cancellation' => $this->cancelled_at === null ? null : [
                'cancelled_at' => $this->cancelled_at->toIso8601String(),
                'cancelled_by' => $this->whenLoaded('cancelledBy', fn (Staff $staff) => $this->staffMember($staff)),
            ],
            'settled_at' => $this->settled_at?->toIso8601String(),
            'chapa_reference' => $this->chapa_reference,
            'payment' => $this->whenLoaded('payment', fn (Payment $payment) => [
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'paid_on' => $payment->paid_on->toDateString(),
            ]),
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function staffMember(Staff $staff): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->user->name,
        ];
    }
}
