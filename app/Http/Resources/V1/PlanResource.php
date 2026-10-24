<?php

namespace App\Http\Resources\V1;

use App\Domains\Memberships\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'duration' => [
                'count' => $this->duration_count,
                'unit' => $this->duration_unit,
            ],
            'freeze_days_allowed' => $this->freeze_days_allowed,
            'visit_limit' => $this->visit_limit,
            'available_at_all_branches' => $this->available_at_all_branches,
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
        ];
    }
}
