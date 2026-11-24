<?php

namespace Database\Factories;

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchClosure>
 */
class BranchClosureFactory extends Factory
{
    protected $model = BranchClosure::class;

    /**
     * Define the model's default state.
     *
     * Closed all day tomorrow for a holiday, added by an owner of the
     * branch's gym.
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
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => fn (array $attributes) => $attributes['starts_on'],
            'reason' => 'Holiday',
            'created_by_staff_id' => fn (array $attributes) => Staff::factory()->owner()->create(['organization_id' => $attributes['organization_id']])->id,
        ];
    }

    /**
     * Open other hours on its days instead of closed all day.
     */
    public function withHours(string $opensAt, string $closesAt): static
    {
        return $this->state(['opens_at' => "{$opensAt}:00", 'closes_at' => "{$closesAt}:00"]);
    }
}
