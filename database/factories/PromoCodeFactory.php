<?php

namespace Database\Factories;

use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    /**
     * Define the model's default state.
     *
     * A code for 10% off every plan, with no dates or total, once per member,
     * made by a staff member of its gym.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => strtoupper(fake()->unique()->bothify('????##')),
            'discount' => '10%',
            'once_per_member' => true,
            'created_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
        ];
    }
}
