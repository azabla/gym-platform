<?php

namespace App\Http\Resources\V1;

use App\Domains\Organizations\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
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
        ];
    }
}
