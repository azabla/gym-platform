<?php

namespace Database\Factories;

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentLink>
 */
class PaymentLinkFactory extends Factory
{
    protected $model = PaymentLink::class;

    /**
     * Define the model's default state.
     *
     * A pending link for the membership's whole price, for a branch of the
     * membership's gym, created by a staff member of that gym.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'organization_id' => fn (array $attributes) => $this->membershipOf($attributes)->organization_id,
            'branch_id' => fn (array $attributes) => Branch::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'amount' => fn (array $attributes) => $this->membershipOf($attributes)->price,
            'tx_ref' => fn () => 'GP-'.Str::ulid(),
            'checkout_url' => fn () => 'https://checkout.chapa.co/checkout/payment/'.Str::random(32),
            'status' => PaymentLinkStatus::Pending,
            'created_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
        ];
    }

    /**
     * Indicate that a staff member of its gym cancelled the link.
     */
    public function cancelled(): static
    {
        return $this->state([
            'status' => PaymentLinkStatus::Cancelled,
            'cancelled_at' => fn () => CarbonImmutable::now(),
            'cancelled_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function membershipOf(array $attributes): Membership
    {
        return Membership::query()->acrossOrganizations()->findOrFail($attributes['membership_id']);
    }
}
