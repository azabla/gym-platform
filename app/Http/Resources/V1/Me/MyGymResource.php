<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Http\Resources\V1\BranchResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gym where the signed-in user is a member, for the members app: the gym,
 * the member record, the membership at a glance (see Membership::atAGlanceFor())
 * and what is still owed there, attached by MyGymController.
 *
 * @mixin Member
 */
class MyGymResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->organization->slug,
            'name' => $this->organization->name,
            'member' => [
                'id' => $this->id,
                'full_name' => $this->full_name,
                'home_branch' => new BranchResource($this->whenLoaded('homeBranch')),
            ],
            'membership' => $this->whenLoaded('membershipAtAGlance', fn (Membership $membership) => [
                'id' => $membership->id,
                'plan' => [
                    'id' => $membership->plan->id,
                    'name' => $membership->plan->name,
                ],
                'status' => $membership->statusOn($this->organization->today()),
                'starts_on' => $membership->starts_on->toDateString(),
                'ends_on' => $membership->ends_on->toDateString(),
            ]),
            'balance_due' => $this->whenLoaded('balanceDue'),
        ];
    }
}
