<?php

namespace Database\Factories;

use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountGroup>
 */
class DiscountGroupFactory extends Factory
{
    protected $model = DiscountGroup::class;

    /**
     * Define the model's default state: 10% off for students, made by a
     * staff member of its gym.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'discount' => '10%',
            'created_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
        ];
    }
}
