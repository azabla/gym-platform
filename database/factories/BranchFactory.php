<?php

namespace Database\Factories;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->city().' Branch',
            'code' => fake()->unique()->bothify('BR-####-????'),
            'address' => null,
        ];
    }
}
