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
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'organization' => [
                'slug' => $this->organization->slug,
                'name' => $this->organization->name,
                // A paused gym answers 403 GYM_PAUSED everywhere else (ADR 0043).
                'paused' => $this->organization->isSuspended(),
            ],
            'role' => $this->role,
            'works_at_all_branches' => $this->works_at_all_branches,
        ];
    }
}
