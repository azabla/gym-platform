<?php

namespace Database\Factories;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipFreeze>
 */
class MembershipFreezeFactory extends Factory
{
    protected $model = MembershipFreeze::class;

    /**
     * Define the model's default state.
     *
     * A week-long freeze from the membership's first day, frozen by a staff
     * member of its gym. It only creates the row: it doesn't move the
     * membership's end date the way Membership::freeze() does.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'organization_id' => fn (array $attributes) => $this->membershipOf($attributes)->organization_id,
            'starts_on' => fn (array $attributes) => $this->membershipOf($attributes)->starts_on,
            'ends_on' => fn (array $attributes) => CarbonImmutable::parse($attributes['starts_on'])->addDays(6),
            'reason' => 'Travelling.',
            'frozen_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function membershipOf(array $attributes): Membership
    {
        return Membership::query()->acrossOrganizations()->findOrFail($attributes['membership_id']);
    }
}
