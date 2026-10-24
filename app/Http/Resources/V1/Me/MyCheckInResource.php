<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Http\Resources\V1\BranchResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the signed-in member's visits, for the members app: when, where,
 * and whether they were let in, with the reason when not. It doesn't show
 * which staff member was at the door.
 *
 * @mixin CheckIn
 */
class MyCheckInResource extends JsonResource
{
    /**
     * The relations the response shows.
     */
    public const RELATIONS = ['branch'];

    /**
     * Transform the resource into an array, with the relations above loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->result === CheckInResult::Allowed ? 'allow' : 'deny',
            'reason_code' => $this->reason_code,
            'method' => $this->method,
            'checked_in_at' => $this->checked_in_at->toIso8601String(),
            'branch' => new BranchResource($this->whenLoaded('branch')),
        ];
    }
}
