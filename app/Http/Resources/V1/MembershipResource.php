<?php

namespace App\Http\Resources\V1;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Membership
 */
class MembershipResource extends JsonResource
{
    /**
     * The relations a full membership response shows. Without them, the
     * response leaves out who sold or cancelled it and its branches.
     */
    public const RELATIONS = ['plan', 'branches', 'freezes.frozenBy.user', 'soldBy.user', 'cancelledBy.user'];

    /**
     * Transform the resource into an array. The status is for today where the gym is.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'plan' => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ],
            'status' => $this->statusOn(app(CurrentOrganization::class)->get()->today()),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'list_price' => $this->list_price,
            'price' => $this->price,
            'discount' => $this->discount_reason === null ? null : [
                'amount' => $this->list_price->subtract($this->price),
                'reason' => $this->discount_reason,
            ],
            'valid_at_all_branches' => $this->valid_at_all_branches,
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'freeze_days_allowed' => $this->freeze_days_allowed,
            'freeze_days_used' => $this->whenLoaded('freezes', fn () => $this->freezeDaysUsed()),
            'freezes' => MembershipFreezeResource::collection($this->whenLoaded('freezes')),
            'sold_by' => $this->whenLoaded('soldBy', fn (Staff $staff) => $this->staffMember($staff)),
            'sold_at' => $this->created_at?->toIso8601String(),
            'cancellation' => $this->cancelled_at === null ? null : [
                'cancelled_at' => $this->cancelled_at->toIso8601String(),
                'cancelled_by' => $this->whenLoaded('cancelledBy', fn (Staff $staff) => $this->staffMember($staff)),
                'reason' => $this->cancellation_reason,
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
