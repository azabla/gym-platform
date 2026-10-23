<?php

namespace Database\Factories;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state: a one-month plan.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'price' => Money::of((string) fake()->numberBetween(500, 5000)),
            'duration_count' => 1,
            'duration_unit' => DurationUnit::Month,
        ];
    }
}
