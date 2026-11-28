<?php

namespace Database\Factories;

use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformPlan>
 */
class PlatformPlanFactory extends Factory
{
    protected $model = PlatformPlan::class;

    /**
     * Define the model's default state: 2,000 ETB a month, 500 for each extra branch, no discounts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'monthly_price' => Money::of('2,000'),
            'extra_branch_price' => Money::of('500'),
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['retired_at' => now()]);
    }
}
