<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\Exceptions\NoCurrentOrganization;
use App\Domains\Organizations\Tenancy\Exceptions\OrganizationMismatch;

it('only sees records of the current organization', function () {
    $organization = Organization::factory()->create();
    $ownBranch = Branch::factory()->for($organization)->create();
    Branch::factory()->for(Organization::factory())->create();

    actingAsOrganization($organization);

    expect(Branch::query()->pluck('id')->all())->toBe([$ownBranch->id]);
});

it('refuses to query without a current organization', function () {
    Branch::query()->get();
})->throws(NoCurrentOrganization::class);

it('assigns new records to the current organization', function () {
    $organization = actingAsOrganization(Organization::factory()->create());

    $branch = Branch::query()->create(['name' => 'Bole Branch', 'code' => 'BOLE']);

    expect($branch->organization_id)->toBe($organization->id);
});

it('refuses to create a record for another organization while acting as one', function () {
    $otherOrganization = Organization::factory()->create();
    actingAsOrganization(Organization::factory()->create());

    Branch::factory()->for($otherOrganization)->create();
})->throws(OrganizationMismatch::class);

it('refuses to create a record without an organization', function () {
    Branch::query()->create(['name' => 'Bole Branch', 'code' => 'BOLE']);
})->throws(NoCurrentOrganization::class);

it('sees every organization only when asked explicitly', function () {
    Branch::factory()->count(2)->create();

    expect(Branch::query()->acrossOrganizations()->count())->toBe(2);
});
