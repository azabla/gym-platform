<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Memberships\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan the signed-in member can buy in the app, with the dates the
 * membership would have if they bought it now: from the day after their
 * last membership ends, or today. See ADR 0013.
 *
 * @mixin Plan
 */
class MyPlanResource extends JsonResource
{
    public function __construct(Plan $plan, private CarbonImmutable $startsOn)
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
