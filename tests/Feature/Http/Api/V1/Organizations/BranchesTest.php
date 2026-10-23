<?php

declare(strict_types=1);

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
