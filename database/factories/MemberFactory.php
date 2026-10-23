<?php

namespace Database\Factories;

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /**
     * Define the model's default state.
     *
     * The member belongs to the gym of their home branch, unless an
     * organization is given explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'home_branch_id' => Branch::factory(),
            'organization_id' => fn (array $attributes) => Branch::query()
                ->acrossOrganizations()
                ->findOrFail($attributes['home_branch_id'])
                ->organization_id,
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('09########'),
        ];
    }
}
