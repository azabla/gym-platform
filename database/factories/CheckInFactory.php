<?php

namespace Database\Factories;

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    protected $model = CheckIn::class;

    /**
     * Define the model's default state.
     *
     * A member let in at the desk just now, at a branch of the member's gym,
     * by a staff member of that gym.
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
            'branch_id' => fn (array $attributes) => Branch::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'method' => CheckInMethod::Desk,
            'result' => CheckInResult::Allowed,
            'checked_in_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'checked_in_at' => fn () => CarbonImmutable::now(),
        ];
    }

    /**
     * Indicate that the member was turned away, for the reason given.
     */
    public function denied(CheckInDenial $denial): static
    {
        return $this->state([
            'result' => CheckInResult::Denied,
            'reason_code' => $denial,
        ]);
    }
}
