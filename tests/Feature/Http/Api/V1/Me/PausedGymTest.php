<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->member = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create(['phone' => '+251911223344']);
    $this->gym->suspend(User::factory()->create(), 'Hasn\'t paid.');
});

it('refuses a paused gym\'s routes to its members with 403 GYM_PAUSED', function (string $route) {
    actingAsMember($this->member);

    $this->getJson(route($route, ['organization' => 'demo-gym']))
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'This gym isn\'t available in the app for now. Please ask at the gym.',
            'code' => 'GYM_PAUSED',
        ]);
})->with([
    'branches' => ['api.v1.me.gyms.branches.index'],
    'check-ins' => ['api.v1.me.gyms.check-ins.index'],
    'the QR code' => ['api.v1.me.gyms.access-credential.show'],
]);

it('still answers 404 to someone who isn\'t a member of the paused gym', function () {
    Sanctum::actingAs(User::factory()->withPhone('+251922334455')->create());

    $this->getJson(route('api.v1.me.gyms.branches.index', ['organization' => 'demo-gym']))->assertNotFound();
});

it('keeps listing a paused gym among the member\'s gyms, marked as paused', function () {
    actingAsMember($this->member);

    $this->getJson(route('api.v1.me.gyms.index'))
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'demo-gym')
        ->assertJsonPath('data.0.paused', true);

    $this->gym->resume(User::factory()->create());

    $this->getJson(route('api.v1.me.gyms.index'))->assertJsonPath('data.0.paused', false);
    $this->getJson(route('api.v1.me.gyms.branches.index', ['organization' => 'demo-gym']))->assertOk();
});
