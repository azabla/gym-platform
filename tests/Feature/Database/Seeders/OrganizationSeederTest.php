<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Database\Seeders\OrganizationSeeder;

it('creates the demo organization with its main branch and owner', function () {
    $this->seed(OrganizationSeeder::class);

    $organization = actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());
    $branch = $organization->branches()->sole();
    $owner = $organization->staff()->sole();

    expect($organization->name)->toBe('Demo Gym')
        ->and($branch->code)->toBe('MAIN')
        ->and($branch->name)->toBe('Main Branch')
        ->and($owner->role)->toBe(StaffRole::Owner)
        ->and($owner->works_at_all_branches)->toBeTrue()
        ->and($owner->user->email)->toBe('owner@demo-gym.test');
});

it('can run more than once without creating duplicates', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(OrganizationSeeder::class);

    expect(Organization::query()->count())->toBe(1)
        ->and(Branch::query()->acrossOrganizations()->count())->toBe(1)
        ->and(Staff::query()->acrossOrganizations()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Organization::query()->where('slug', 'demo-gym')->exists())->toBeTrue();
});
