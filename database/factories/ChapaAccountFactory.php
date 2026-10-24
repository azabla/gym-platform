<?php

namespace Database\Factories;

use App\Domains\Billing\Enums\ChapaMode;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChapaAccount>
 */
class ChapaAccountFactory extends Factory
{
    protected $model = ChapaAccount::class;

    /**
     * Define the model's default state.
     *
     * A Chapa test key, connected by the gym's owner.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'secret_key' => fn () => 'CHASECK_TEST-'.Str::random(32),
            'mode' => fn (array $attributes) => ChapaMode::ofKey($attributes['secret_key']),
            'key_hint' => fn (array $attributes) => substr($attributes['secret_key'], -4),
            'connected_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
                'role' => StaffRole::Owner,
            ]),
        ];
    }
}
