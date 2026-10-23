<?php

namespace App\Http\Resources\V1;

use App\Domains\Members\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Member
 */
class MemberResource extends JsonResource
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
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'emergency_contact' => $this->emergency_contact_name === null ? null : [
                'name' => $this->emergency_contact_name,
                'phone' => $this->emergency_contact_phone,
            ],
            'home_branch' => new BranchResource($this->whenLoaded('homeBranch')),
            'registered_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
