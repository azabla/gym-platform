<?php

namespace App\Http\Resources\V1;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment or a refund, with everything a printed receipt shows.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * The relations a receipt shows. Without them, it leaves out the branch,
     * the member, the membership and who received or voided the money. The
     * membership's freezes give its status without a query per receipt.
     */
    private const RELATIONS = ['branch', 'membership.plan', 'membership.member', 'membership.freezes', 'receivedBy.user', 'voidedBy.user'];

    /**
     * Load everything a receipt shows: the relations above and the balance of
     * the membership as it is now. One query per relation, however many payments.
     */
    public static function loadDetails(Payment ...$payments): void
    {
        (new Collection($payments))->load(self::RELATIONS);

        MembershipResource::attachBalances(
            ...array_map(fn (Payment $payment): Membership => $payment->membership, $payments),
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
            'receipt_number' => $this->receipt_number,
            'type' => $this->type,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'paid_on' => $this->paid_on->toDateString(),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'member' => $this->whenLoaded('membership', fn (Membership $membership) => [
                'id' => $membership->member->id,
                'full_name' => $membership->member->full_name,
                'phone' => $membership->member->phone,
            ]),
            'membership' => new MembershipResource($this->whenLoaded('membership')),
            'received_by' => $this->whenLoaded('receivedBy', fn (Staff $staff) => $this->staffMember($staff)),
            'recorded_at' => $this->created_at?->toIso8601String(),
            'void' => $this->voided_at === null ? null : [
                'voided_at' => $this->voided_at->toIso8601String(),
                'voided_by' => $this->whenLoaded('voidedBy', fn (Staff $staff) => $this->staffMember($staff)),
                'reason' => $this->void_reason,
            ],
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
