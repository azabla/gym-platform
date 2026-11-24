<?php

namespace App\Http\Resources\V1;

use App\Domains\Organizations\Models\BranchClosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Days a branch is closed, or open other hours (ADR 0037): opens_at and
 * closes_at are null when it is closed all day; closing earlier than opening
 * means after midnight.
 *
 * @mixin BranchClosure
 */
class BranchClosureResource extends JsonResource
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
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'reason' => $this->reason,
            'opens_at' => $this->opens_at === null ? null : substr((string) $this->opens_at, 0, 5),
            'closes_at' => $this->closes_at === null ? null : substr((string) $this->closes_at, 0, 5),
        ];
    }
}
