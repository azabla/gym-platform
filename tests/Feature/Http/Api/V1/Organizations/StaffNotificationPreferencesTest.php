<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
});

it('shows a staff member their push and SMS choices at the gym, push on and SMS off at first', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(route('api.v1.organizations.notification-preferences.show', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertExactJson(['data' => [
            'online_payments' => ['push' => true, 'sms' => false],
            'refunds' => ['push' => true, 'sms' => false],
            'app_freezes' => ['push' => true, 'sms' => false],
            'members_to_call' => ['push' => true, 'sms' => false],
        ]]);
});

it('changes only the choices sent, and only the staff member\'s own', function () {
    $staff = actingAsStaff($this->gym, StaffRole::Manager);
    $colleague = Staff::factory()->for($this->gym)->create();

    $this->patchJson(route('api.v1.organizations.notification-preferences.update', ['organization' => 'demo-gym']), [
        'refunds' => ['sms' => true],
        'members_to_call' => ['push' => false],
    ])
        ->assertOk()
        ->assertJsonPath('data.refunds', ['push' => true, 'sms' => true])
        ->assertJsonPath('data.members_to_call', ['push' => false, 'sms' => false]);

    expect($staff->refresh()->notificationChoices()['refunds']['sms'])->toBeTrue()
        ->and($colleague->refresh()->notificationChoices()['refunds']['sms'])->toBeFalse();
});

it('refuses an unknown topic or a choice that isn\'t true or false', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->patchJson(route('api.v1.organizations.notification-preferences.update', ['organization' => 'demo-gym']), [
        'payments' => ['push' => false],
        'refunds' => ['sms' => 'yes'],
        'app_freezes' => ['email' => true],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payments', 'refunds.sms', 'app_freezes']);
});
