<?php

namespace Database\Factories;

use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessCredential>
 */
class AccessCredentialFactory extends Factory
{
    protected $model = AccessCredential::class;

    /**
     * Define the model's default state.
     *
     * An active GQ1 credential of a member, with a random secret.
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
            'user_id' => User::factory(),
            'secret' => fn () => base64_encode(random_bytes(32)),
            'algorithm' => AccessCredential::ALGORITHM,
        ];
    }
}
