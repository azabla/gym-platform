<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->tigist = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create(['phone' => '0922 34 56 78']);
});

/**
 * Put a message in the member's inbox, as the notifications do.
 *
 * @param  array<string, mixed>  $details
 */
function inboxMessage(Member $member, string $type, array $details, array $data = [], ?CarbonImmutable $readAt = null): DatabaseNotification
{
    return $member->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => $type,
        'data' => ['details' => $details, 'data' => $data],
        'read_at' => $readAt,
    ]);
}

it('lists my messages at the gym, newest first, written in my language, with the unread count', function () {
    $older = inboxMessage($this->tigist, 'membership_expiring', ['plan' => 'Monthly', 'gym' => 'Demo Gym', 'ends_on' => '2026-10-27', 'count' => 3], ['membership_id' => '7'], now()->toImmutable());
    $this->travel(1)->hour();
    $newer = inboxMessage($this->tigist, 'visit_denied', ['branch' => 'Main Branch', 'reason' => ['translate' => 'notifications.reasons.MEMBERSHIP_EXPIRED']], ['check_in_id' => '41']);
    $atAnotherGym = Member::factory()->create(['phone' => '0922 34 56 78']);
    inboxMessage($atAnotherGym, 'membership_expired', ['plan' => 'Yearly', 'gym' => 'Other Gym', 'ends_on' => '2026-10-20']);
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.notifications.index', ['organization' => 'demo-gym']), ['Accept-Language' => 'am,en;q=0.8'])
        ->assertOk()
        ->assertJsonPath('data.*.id', [$newer->id, $older->id])
        ->assertJsonPath('data.0.type', 'visit_denied')
        ->assertJsonPath('data.0.title', 'Check-in refused')
        ->assertJsonPath('data.0.body', 'You couldn\'t check in at Main Branch: your membership has ended.')
        ->assertJsonPath('data.0.data', ['check_in_id' => '41'])
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.1.body', 'Your Monthly membership at Demo Gym ends in 3 days, on 2026-10-27.')
        ->assertJsonPath('data.1.read_at', fn ($readAt) => $readAt !== null)
        ->assertJsonPath('meta.unread_count', 1);
});

it('says "tomorrow" when one day is left', function () {
    inboxMessage($this->tigist, 'membership_expiring', ['plan' => 'Monthly', 'gym' => 'Demo Gym', 'ends_on' => '2026-10-25', 'count' => 1]);
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.notifications.index', ['organization' => 'demo-gym']))
        ->assertJsonPath('data.0.body', 'Your Monthly membership at Demo Gym ends tomorrow, 2026-10-25.');
});

it('marks one message read, and all of this gym\'s, safely more than once', function () {
    $first = inboxMessage($this->tigist, 'membership_expired', ['plan' => 'Monthly', 'gym' => 'Demo Gym', 'ends_on' => '2026-10-23']);
    $second = inboxMessage($this->tigist, 'membership_expired', ['plan' => 'Monthly', 'gym' => 'Demo Gym', 'ends_on' => '2026-10-23']);
    $atAnotherGym = inboxMessage(Member::factory()->create(['phone' => '0922 34 56 78']), 'membership_expired', ['plan' => 'Yearly', 'gym' => 'Other Gym', 'ends_on' => '2026-10-20']);
    actingAsMember($this->tigist);
    $read = route('api.v1.me.gyms.notifications.read', ['organization' => 'demo-gym', 'notification' => $first->id]);

    $this->postJson($read)->assertNoContent();
    $this->postJson($read)->assertNoContent();
    expect($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->toBeNull();

    $this->postJson(route('api.v1.me.gyms.notifications.read-all', ['organization' => 'demo-gym']))->assertNoContent();
    $this->postJson(route('api.v1.me.gyms.notifications.read-all', ['organization' => 'demo-gym']))->assertNoContent();
    expect($second->fresh()->read_at)->not->toBeNull()
        ->and($atAnotherGym->fresh()->read_at)->toBeNull();
});

it('cannot read another member\'s message', function () {
    $someoneElses = inboxMessage(Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create(), 'membership_expired', ['plan' => 'Monthly', 'gym' => 'Demo Gym', 'ends_on' => '2026-10-23']);
    actingAsMember($this->tigist);

    $this->postJson(route('api.v1.me.gyms.notifications.read', ['organization' => 'demo-gym', 'notification' => $someoneElses->id]))
        ->assertNotFound();
    expect($someoneElses->fresh()->read_at)->toBeNull();
});

it('has every push topic on until I turn one off, at this gym only', function () {
    $atAnotherGym = Member::factory()->create(['phone' => '0922 34 56 78']);
    actingAsMember($this->tigist);
    $preferences = route('api.v1.me.gyms.notification-preferences.show', ['organization' => 'demo-gym']);

    $this->getJson($preferences)
        ->assertOk()
        ->assertExactJson(['data' => ['membership' => true, 'payments' => true, 'visits' => true, 'announcements' => true]]);

    $this->patchJson(route('api.v1.me.gyms.notification-preferences.update', ['organization' => 'demo-gym']), ['visits' => false, 'announcements' => true])
        ->assertOk()
        ->assertExactJson(['data' => ['membership' => true, 'payments' => true, 'visits' => false, 'announcements' => true]]);

    expect($this->tigist->fresh()->pushPreferences())->toBe(['membership' => true, 'payments' => true, 'visits' => false, 'announcements' => true])
        ->and($atAnotherGym->fresh()->pushPreferences())->toBe(['membership' => true, 'payments' => true, 'visits' => true, 'announcements' => true]);
});

it('refuses an unknown preference or one that is not true or false', function () {
    actingAsMember($this->tigist);

    $this->patchJson(route('api.v1.me.gyms.notification-preferences.update', ['organization' => 'demo-gym']), ['visits' => 'no', 'marketing' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['visits', 'marketing']);
});
