<?php

namespace App\Http\Resources\V1;

use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Staff;
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
     * the member, the membership and who received the money. The membership's
     * freezes give its status without a query per receipt.
     */
    public const RELATIONS = ['branch', 'membership.plan', 'membership.member', 'membership.freezes', 'receivedBy.user'];

    /**
     * Transform the resource into an array. The membership shows its balance
     * as it is now, when the controller attached it.
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
            'received_by' => $this->whenLoaded('receivedBy', fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => $staff->user->name,
            ]),
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
