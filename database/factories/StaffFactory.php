<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => StaffRole::Receptionist,
            'works_at_all_branches' => true,
        ];
    }

    /**
     * Indicate that the staff member owns the organization.
     */
    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => StaffRole::Owner,
        ]);
    }
}
