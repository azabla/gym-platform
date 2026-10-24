<?php

namespace Database\Factories;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * Define the model's default state.
     *
     * An announcement to every branch, scheduled for tomorrow, posted by a
     * staff member of its gym.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'posted_by_staff_id' => fn (array $attributes) => Staff::factory()->state([
                'organization_id' => $attributes['organization_id'],
            ]),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'send_at' => fn () => CarbonImmutable::now()->addDay(),
        ];
    }

    /**
     * Indicate that it went out an hour ago, to the given number of members.
     */
    public function sent(int $recipientsCount = 0): static
    {
        return $this->state(fn () => [
            'send_at' => CarbonImmutable::now()->subHour(),
            'sent_at' => CarbonImmutable::now()->subHour(),
            'recipients_count' => $recipientsCount,
        ]);
    }

    /**
     * Indicate that it was cancelled before it went.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => ['cancelled_at' => CarbonImmutable::now()]);
    }
}
