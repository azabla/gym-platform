<?php

namespace App\Http\Resources\V1;

use App\Domains\Organizations\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch, with its weekly opening hours and its closures from today to
 * 60 days ahead when they are loaded (ADR 0016 and ADR 0037).
 *
 * @mixin Branch
 */
class BranchResource extends JsonResource
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
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'opening_hours' => $this->whenLoaded('openingHours', fn () => OpeningHoursResource::collection($this->openingHours)),
            'closures' => $this->whenLoaded('closures', fn () => BranchClosureResource::collection($this->closures)),
        ];
    }
}
