<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->staff = actingAsStaff($this->gym, StaffRole::Owner);
    $this->gym->suspend(User::factory()->create(), 'Hasn\'t paid.');
});

it('refuses everything at a paused gym with 403 GYM_PAUSED: the desk, the door and offline uploads', function (string $method, string $route) {
    $this->json($method, route($route, ['organization' => 'demo-gym']))
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'This gym\'s account is paused for now. Please contact the platform\'s admins.',
            'code' => 'GYM_PAUSED',
        ]);
})->with([
    'members' => ['GET', 'api.v1.organizations.members.index'],
    'payments' => ['GET', 'api.v1.organizations.payments.index'],
    'a scan at the door' => ['POST', 'api.v1.organizations.check-ins.scan'],
    'the door list' => ['GET', 'api.v1.organizations.door-list.show'],
    'offline scans' => ['POST', 'api.v1.organizations.check-ins.offline.store'],
]);

it('still answers 404 to someone who doesn\'t work at the paused gym', function () {
    actingAsStaff(Organization::factory()->create(['slug' => 'other-gym']), StaffRole::Owner);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))->assertNotFound();
});

it('says which of the staff member\'s gyms are paused', function () {
    $this->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.staff.0.organization.paused', true);

    $this->gym->resume(User::factory()->create());

    $this->getJson(route('api.v1.me'))->assertJsonPath('data.staff.0.organization.paused', false);
    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))->assertOk();
});
