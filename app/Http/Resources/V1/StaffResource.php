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
            ],
            'role' => $this->role,
            'works_at_all_branches' => $this->works_at_all_branches,
        ];
    }
}
