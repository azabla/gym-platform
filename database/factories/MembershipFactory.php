<?php

namespace Database\Factories;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * Define the model's default state.
     *
     * A one-month membership starting today, in the member's gym, sold by a
     * staff member of that gym. The end date, list price and price come from
     * the plan unless they are given explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'organization_id' => fn (array $attributes) => Member::query()
                ->acrossOrganizations()
                ->findOrFail($attributes['member_id'])
                ->organization_id,
            'plan_id' => fn (array $attributes) => Plan::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'starts_on' => fn () => CarbonImmutable::today(),
            'ends_on' => fn (array $attributes) => $this->planOf($attributes)
                ->endsOnFor(CarbonImmutable::parse($attributes['starts_on'])),
            'list_price' => fn (array $attributes) => $this->planOf($attributes)->price,
            'price' => fn (array $attributes) => $this->planOf($attributes)->price,
            'sold_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
        ];
    }

    /**
     * Indicate that the membership was cancelled by a staff member of its gym.
     */
    public function cancelled(): static
    {
        return $this->state([
            'cancelled_at' => fn () => CarbonImmutable::now(),
            'cancelled_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'cancellation_reason' => 'Sold by mistake.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function planOf(array $attributes): Plan
    {
        return Plan::query()->acrossOrganizations()->withTrashed()->findOrFail($attributes['plan_id']);
    }
}
