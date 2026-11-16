<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Laravel\Sanctum\Sanctum;

it('requires a token', function () {
    $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'demo-gym']))
        ->assertUnauthorized();
});

it('lists every branch to staff who work at all branches', function () {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    Branch::factory()->for($organization)->create(['name' => 'Main Branch']);
    Branch::factory()->for($organization)->create(['name' => 'Bole Branch']);
    Branch::factory()->create(['name' => 'Another Gym Branch']);
    Sanctum::actingAs(Staff::factory()->for($organization)->create()->user);

    $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Bole Branch', 'Main Branch']);
});

it('lists only their branches to staff assigned to specific branches', function () {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    $mainBranch = Branch::factory()->for($organization)->create(['name' => 'Main Branch']);
    Branch::factory()->for($organization)->create(['name' => 'Bole Branch']);
    $staff = Staff::factory()->for($organization)->create();
    $staff->assignToBranches($mainBranch);
    Sanctum::actingAs($staff->user);

    $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Main Branch']);
});

it('gives the same 404 for an unknown gym and a gym the user does not work at', function () {
    Organization::factory()->create(['slug' => 'other-gym']);
    Sanctum::actingAs(Staff::factory()->create()->user);

    $unknownGym = $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'no-such-gym']));
    $otherGym = $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'other-gym']));

    $unknownGym->assertNotFound();
    $otherGym->assertNotFound();
    expect($otherGym->json())->toBe($unknownGym->json());
});

it('shows a branch the staff member works at', function () {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    $branch = Branch::factory()->for($organization)->create(['code' => 'MAIN']);
    Sanctum::actingAs(Staff::factory()->for($organization)->create()->user);

    $this->getJson(route('api.v1.organizations.branches.show', ['organization' => 'demo-gym', 'branch' => $branch]))
        ->assertOk()
        ->assertJsonPath('data.code', 'MAIN');
});

it('forbids a branch of their gym they are not assigned to', function () {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    $mainBranch = Branch::factory()->for($organization)->create();
    $boleBranch = Branch::factory()->for($organization)->create();
    $staff = Staff::factory()->for($organization)->create();
    $staff->assignToBranches($mainBranch);
    Sanctum::actingAs($staff->user);

    $this->getJson(route('api.v1.organizations.branches.show', ['organization' => 'demo-gym', 'branch' => $boleBranch]))
        ->assertForbidden();
});

it('cannot reach a branch of another gym by its id', function () {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    $otherGymsBranch = Branch::factory()->create();
    Sanctum::actingAs(Staff::factory()->for($organization)->create()->user);

    $this->getJson(route('api.v1.organizations.branches.show', ['organization' => 'demo-gym', 'branch' => $otherGymsBranch]))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

it('lets an owner add a branch, its code stored in capitals and always open', function () {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    actingAsStaff($organization, StaffRole::Owner);

    $this->postJson(route('api.v1.organizations.branches.store', ['organization' => 'demo-gym']), [
        'name' => 'Piassa Branch',
        'code' => ' piassa ',
        'address' => 'Piassa, Addis Ababa',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Piassa Branch')
        ->assertJsonPath('data.code', 'PIASSA');

    expect(Branch::query()->acrossOrganizations()->where('code', 'PIASSA')->sole()->organization_id)->toBe($organization->id);
});

it('refuses a code another branch of the gym has, or one that can\'t be a code', function (string $code) {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    Branch::factory()->for($organization)->create(['code' => 'BOLE']);
    actingAsStaff($organization, StaffRole::Owner);

    $this->postJson(route('api.v1.organizations.branches.store', ['organization' => 'demo-gym']), ['name' => 'Bole 2', 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
})->with(['taken, in other capitals' => 'bole', 'with a space' => 'BOLE 2', 'too short' => 'B']);

it('lets only owners add branches', function (StaffRole $role) {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    actingAsStaff($organization, $role);

    $this->postJson(route('api.v1.organizations.branches.store', ['organization' => 'demo-gym']), ['name' => 'Piassa Branch', 'code' => 'PIASSA'])
        ->assertForbidden();
})->with([StaffRole::Manager, StaffRole::Receptionist]);

it('lets a manager rename a branch they work at, but not another', function () {
    $organization = Organization::factory()->create(['slug' => 'demo-gym']);
    $bole = Branch::factory()->for($organization)->create(['name' => 'Bole Branch', 'code' => 'BOLE']);
    $main = Branch::factory()->for($organization)->create(['code' => 'MAIN']);
    actingAsStaff($organization, StaffRole::Manager, $bole);

    $this->patchJson(route('api.v1.organizations.branches.update', ['organization' => 'demo-gym', 'branch' => $bole]), ['name' => 'Bole Atlas', 'code' => 'BOLE'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bole Atlas');

    $this->patchJson(route('api.v1.organizations.branches.update', ['organization' => 'demo-gym', 'branch' => $main]), ['name' => 'Renamed'])
        ->assertForbidden();
});
