<?php

namespace App\Http\Resources\V1;

use App\Domains\Memberships\Models\MemberDiscount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's discount group, and until when (ADR 0032).
 *
 * @mixin MemberDiscount
 */
class MemberDiscountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->discountGroup?->id,
            'name' => $this->discountGroup?->name,
            'discount' => $this->discountGroup?->discount,
            'until' => $this->until?->toDateString(),
        ];
    }
}
