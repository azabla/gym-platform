<?php

namespace App\Http\Resources\V1;

use App\Domains\Organizations\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Staff
 */
class StaffResource extends JsonResource
{
    /**
     * The relations the resource shows.
     */
    public const RELATIONS = ['user', 'branches'];

    /**
     * Transform the resource into an array. The branches are empty for staff
     * who work at all branches; an invite is pending until the staff member
     * has set a password (ADR 0019).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'phone' => $this->user->phone,
            'email' => $this->user->email,
            'role' => $this->role,
            'works_at_all_branches' => $this->works_at_all_branches,
            'branches' => $this->works_at_all_branches ? [] : BranchResource::collection($this->whenLoaded('branches')),
            'invite_pending' => $this->user->password === null,
            'archived_at' => $this->deleted_at?->toIso8601String(),
            'added_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
