<?php

namespace Database\Factories;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchOpeningHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchOpeningHour>
 */
class BranchOpeningHourFactory extends Factory
{
    protected $model = BranchOpeningHour::class;

    /**
     * Define the model's default state.
     *
     * Open on Mondays from 06:00 to 22:00, at a branch of the branch's gym.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'organization_id' => fn (array $attributes) => Branch::query()
                ->acrossOrganizations()
                ->findOrFail($attributes['branch_id'])
                ->organization_id,
            'weekday' => 1,
            'opens_at' => '06:00:00',
            'closes_at' => '22:00:00',
        ];
    }
}
