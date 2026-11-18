<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
});

it('shows every staff member the gym\'s settings', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(route('api.v1.organizations.settings.show', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertExactJson(['data' => [
            'name' => 'Demo Gym',
            'slug' => 'demo-gym',
            'timezone' => 'Africa/Addis_Ababa',
            'message_language' => 'en',
        ]]);
});

it('lets owners choose the language members get messages in', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->patchJson(route('api.v1.organizations.settings.update', ['organization' => 'demo-gym']), ['message_language' => 'am'])
        ->assertOk()
        ->assertJsonPath('data.message_language', 'am');

    $this->patchJson(route('api.v1.organizations.settings.update', ['organization' => 'demo-gym']), ['message_language' => 'fr'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message_language']);

    expect($this->gym->refresh()->message_language->value)->toBe('am');
});

it('lets no one but owners change the settings', function (StaffRole $role) {
    actingAsStaff($this->gym, $role);

    $this->patchJson(route('api.v1.organizations.settings.update', ['organization' => 'demo-gym']), ['message_language' => 'am'])
        ->assertForbidden();
})->with([StaffRole::Manager, StaffRole::Accountant, StaffRole::Receptionist, StaffRole::Trainer]);
