<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Notifications\MembersToCall;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
});

it('shows a staff member their own messages at the gym, newest first, with the unread count', function () {
    $staff = actingAsStaff($this->gym, StaffRole::Manager);
    $staff->notify(MembersToCall::count(1));
    $this->travel(1)->minutes();
    $staff->notify(MembersToCall::count(3));
    Staff::factory()->for($this->gym)->create()->notify(MembersToCall::count(9));

    $this->getJson(route('api.v1.organizations.notifications.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'staff_members_to_call')
        ->assertJsonPath('data.0.title', 'Members to call')
        ->assertJsonPath('data.0.body', '3 members to call: memberships ending in the next 7 days with nothing sold after them.')
        ->assertJsonPath('data.1.body', '1 member to call: a membership ends in the next 7 days with nothing sold after it.')
        ->assertJsonPath('meta.unread_count', 2);
});

it('marks one message read, or all of them', function () {
    $staff = actingAsStaff($this->gym, StaffRole::Receptionist);
    $staff->notify(MembersToCall::count(1));
    $staff->notify(MembersToCall::count(2));
    $first = $staff->notifications()->firstOrFail();

    $this->postJson(route('api.v1.organizations.notifications.read', ['organization' => 'demo-gym', 'notification' => $first->id]))
        ->assertNoContent();

    expect($staff->unreadNotifications()->count())->toBe(1);

    $this->postJson(route('api.v1.organizations.notifications.read-all', ['organization' => 'demo-gym']))->assertNoContent();

    expect($staff->unreadNotifications()->count())->toBe(0);
});

it('keeps another staff member\'s message out of reach', function () {
    actingAsStaff($this->gym, StaffRole::Owner);
    $someoneElse = Staff::factory()->for($this->gym)->create();
    $someoneElse->notify(MembersToCall::count(1));

    $this->postJson(route('api.v1.organizations.notifications.read', [
        'organization' => 'demo-gym',
        'notification' => $someoneElse->notifications()->firstOrFail()->id,
    ]))->assertNotFound();
});
