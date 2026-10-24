<?php

namespace App\Http\Resources\V1;

use App\Domains\Organizations\Models\BranchOpeningHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch's hours on one weekday (1 is Monday, 7 is Sunday), as "HH:MM" in
 * the gym's time zone.
 *
 * @mixin BranchOpeningHour
 */
class OpeningHoursResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'weekday' => $this->weekday,
            'opens_at' => substr($this->opens_at, 0, 5),
            'closes_at' => substr($this->closes_at, 0, 5),
        ];
    }
}
