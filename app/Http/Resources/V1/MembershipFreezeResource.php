<?php

namespace App\Http\Resources\V1;

use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MembershipFreeze
 */
class MembershipFreezeResource extends JsonResource
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
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'days' => $this->days(),
            'reason' => $this->reason,
            'frozen_by' => $this->whenLoaded('frozenBy', fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => $staff->user->name,
            ]),
        ];
    }
}
