<?php

namespace App\Http\Resources\V1;

use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A promo code with its limits and how many times it has been used
 * (memberships sold with it). See ADR 0030.
 *
 * @mixin PromoCode
 */
class PromoCodeResource extends JsonResource
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
            'discount' => $this->discount,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'max_uses' => $this->max_uses,
            'uses' => $this->memberships_count ?? $this->usesCount(),
            'once_per_member' => $this->once_per_member,
            'available_for_all_plans' => $this->available_for_all_plans,
            'plans' => $this->whenLoaded('plans', fn () => $this->plans->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
            ])->all()),
            'archived' => $this->deleted_at !== null,
        ];
    }
}
