<?php

namespace Database\Factories;

use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Models\PushToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
{
    protected $model = PushToken::class;

    /**
     * Define the model's default state.
     *
     * A push token for a new sign-in of a user, with no language given.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'personal_access_token_id' => fn (array $attributes) => User::query()
                ->findOrFail((int) $attributes['user_id'])
                ->createToken('phone')
                ->accessToken
                ->getKey(),
            'token' => fn () => fake()->unique()->regexify('[A-Za-z0-9_-]{40}:APA91b[A-Za-z0-9_-]{60}'),
        ];
    }
}
