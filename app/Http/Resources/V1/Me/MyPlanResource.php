<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Memberships\Pricing\PriceChoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan the signed-in member can buy in the app, with the dates the
 * membership would have if they bought it now: from the day after their
 * last membership ends, or today. With a promo code, also its price with the
 * code, or null where the code doesn't apply. your_price is what the member
 * would pay, through their discount group or the code, and your_discount
 * says which, or both are null at full price. See ADR 0013, ADR 0030 and
 * ADR 0032.
 *
 * @mixin Plan
 */
class MyPlanResource extends JsonResource
{
    public function __construct(
        Plan $plan,
        private CarbonImmutable $startsOn,
        private ?PromoCode $promoCode = null,
        private ?PriceChoice $priceChoice = null,
    ) {
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
            'your_price' => $this->priceChoice?->isDiscounted() === true ? $this->priceChoice->price : null,
            'your_discount' => match (true) {
                $this->priceChoice?->promoCode !== null => ['source' => 'promo_code', 'name' => $this->priceChoice->promoCode->code],
                $this->priceChoice?->discountGroup !== null => ['source' => 'discount_group', 'name' => $this->priceChoice->discountGroup->name],
                default => null,
            },
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
