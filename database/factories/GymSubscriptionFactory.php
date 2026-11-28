<?php

namespace Database\Factories;

use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GymSubscription>
 */
class GymSubscriptionFactory extends Factory
{
    protected $model = GymSubscription::class;

    /**
     * Define the model's default state: billed monthly on a plan, the next period starting today, no trial.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'platform_plan_id' => PlatformPlan::factory(),
            'currency' => 'ETB',
            'months_per_invoice' => 1,
            'next_period_starts_on' => now('Africa/Addis_Ababa')->toDateString(),
            'grace_days' => 14,
        ];
    }
}
