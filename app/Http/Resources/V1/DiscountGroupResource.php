<?php

namespace App\Http\Resources\V1;

use App\Domains\Memberships\Models\DiscountGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A discount group with how many members are in it. See ADR 0032.
 *
 * @mixin DiscountGroup
 */
class DiscountGroupResource extends JsonResource
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
            'discount' => $this->discount,
            'members' => $this->member_discounts_count ?? $this->memberDiscounts()->count(),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
