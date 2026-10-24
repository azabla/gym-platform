<?php

namespace Database\Factories;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * A cash payment of the membership's whole price today, with the gym's next
     * receipt number, taken at a branch of the membership's gym by a staff
     * member of that gym.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'organization_id' => fn (array $attributes) => $this->membershipOf($attributes)->organization_id,
            'receipt_number' => fn (array $attributes) => Payment::nextReceiptNumber($attributes['organization_id']),
            'type' => PaymentType::Payment,
            'amount' => fn (array $attributes) => $this->membershipOf($attributes)->price,
            'method' => PaymentMethod::Cash,
            'reference' => null,
            'paid_on' => fn () => CarbonImmutable::today(),
            'branch_id' => fn (array $attributes) => Branch::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'received_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
        ];
    }

    /**
     * Indicate that the payment was made by Telebirr, with the given transaction number.
     */
    public function byTelebirr(string $reference): static
    {
        return $this->state([
            'method' => PaymentMethod::Telebirr,
            'reference' => $reference,
        ]);
    }

    /**
     * Indicate that the money was given back to the member.
     */
    public function refund(): static
    {
        return $this->state([
            'type' => PaymentType::Refund,
        ]);
    }

    /**
     * Indicate that the payment was recorded by mistake and voided by a staff
     * member of its gym.
     */
    public function voided(): static
    {
        return $this->state([
            'voided_at' => fn () => CarbonImmutable::now(),
            'voided_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'void_reason' => 'Recorded twice.',
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
