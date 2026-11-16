<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan the signed-in member can buy in the app, with the dates the
 * membership would have if they bought it now: from the day after their
 * last membership ends, or today. With a promo code, also its price with the
 * code, or null where the code doesn't apply. See ADR 0013 and ADR 0030.
 *
 * @mixin Plan
 */
class MyPlanResource extends JsonResource
{
    public function __construct(Plan $plan, private CarbonImmutable $startsOn, private ?PromoCode $promoCode = null)
    {
        parent::__construct($plan);
    }

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
            'price_with_code' => $this->promoCode?->priceIfFor($this->resource),
            'duration' => [
                'count' => $this->duration_count,
                'unit' => $this->duration_unit,
            ],
            'freeze_days_allowed' => $this->freeze_days_allowed,
            'valid_at_all_branches' => $this->available_at_all_branches,
            'starts_on' => $this->startsOn->toDateString(),
            'ends_on' => $this->endsOnFor($this->startsOn)->toDateString(),
        ];
    }
}
