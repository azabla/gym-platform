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
            'price' => $this->price,
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
