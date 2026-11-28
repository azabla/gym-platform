<?php

namespace App\Http\Resources\V1;

use App\Domains\Billing\Models\CashCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member's cash count at a branch (ADR 0046): what they should have
 * had, what they counted, the difference (negative when short) and whether
 * it is checked.
 *
 * @mixin CashCount
 */
class CashCountResource extends JsonResource
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
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'staff' => $this->whenLoaded('staff', fn () => ['id' => $this->staff->id, 'name' => $this->staff->user->name]),
            'expected' => $this->expected,
            'counted' => $this->counted,
            'difference' => $this->difference(),
            'note' => $this->note,
            'closed_at' => $this->closed_at->toIso8601String(),
            'checked' => $this->checked_at !== null,
            'checked_at' => $this->checked_at?->toIso8601String(),
            'check_note' => $this->check_note,
        ];
    }
}
