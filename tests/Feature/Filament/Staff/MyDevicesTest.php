<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\SignInApp;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\SignIns;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\Pages\MyDevices;
use App\Filament\Staff\Resources\Staff\Pages\EditStaff;
use App\Filament\Staff\Resources\Staff\StaffResource;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
});

it('lists the phones signed in as the staff member and signs one out', function () {
    $me = actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $tablet = SignIns::start($me->user, 'Front desk tablet', SignInApp::Staff);
    SignIns::start($me->user, 'My phone', SignInApp::Members);
    SignIns::start(User::factory()->create(), 'Someone else\'s phone', SignInApp::Staff);

    Livewire::test(MyDevices::class)
        ->assertSee(['Front desk tablet', 'My phone', 'Staff app', 'Members app'])
        ->assertDontSee('Someone else')
        ->callTableAction('signOut', $tablet->accessToken)
        ->assertNotified('Signed out.');

    expect(PersonalAccessToken::query()->whereKey($tablet->accessToken->id)->exists())->toBeFalse();

    fromPhone($tablet->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});

it('signs every phone out at once', function () {
    $me = actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    SignIns::start($me->user, 'Tablet', SignInApp::Staff);
    SignIns::start($me->user, 'Phone', SignInApp::Members);

    Livewire::test(MyDevices::class)
        ->callAction('signOutEverywhere')
        ->assertNotified('2 phones signed out.');

    expect($me->user->tokens()->count())->toBe(0);
});

it('lets an owner sign a staff member out of the staff app on every phone, leaving their members app', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $desk = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    SignIns::start($desk->user, 'Lost phone', SignInApp::Staff);
    SignIns::start($desk->user, 'Tablet', SignInApp::Staff);
    $member = SignIns::start($desk->user, 'Phone', SignInApp::Members);

    Livewire::test(EditStaff::class, ['record' => $desk->getRouteKey()])
        ->callAction('signOutEverywhere')
        ->assertNotified('2 phones signed out.');

    expect($desk->user->tokens()->pluck('id')->all())->toBe([$member->accessToken->id]);
});

it('doesn\'t let a receptionist sign another staff member out', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $other = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    SignIns::start($other->user, 'Phone', SignInApp::Staff);

    $this->get(StaffResource::getUrl('edit', ['record' => $other], tenant: $this->gym))->assertForbidden();
});

it('lists and signs out the user\'s own phones in the API, and never another person\'s', function () {
    $user = User::factory()->create();
    $current = SignIns::start($user, 'This phone', SignInApp::Members);
    $old = SignIns::start($user, 'Old phone', SignInApp::Members);
    $someoneElse = SignIns::start(User::factory()->create(), 'Not mine', SignInApp::Members);

    fromPhone($current->plainTextToken)->getJson('/api/v1/me/devices')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['name' => 'This phone', 'app' => 'members', 'current' => true])
        ->assertJsonFragment(['name' => 'Old phone', 'current' => false]);

    fromPhone($current->plainTextToken)->deleteJson("/api/v1/me/devices/{$someoneElse->accessToken->id}")->assertNotFound();
    fromPhone($current->plainTextToken)->deleteJson("/api/v1/me/devices/{$old->accessToken->id}")->assertNoContent();
    fromPhone($old->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
});
