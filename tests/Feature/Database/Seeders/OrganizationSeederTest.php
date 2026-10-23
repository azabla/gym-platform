<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Database\Seeders\OrganizationSeeder;

it('creates the demo organization with its branches and staff', function () {
    $this->seed(OrganizationSeeder::class);

    $organization = actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());
    $mainBranch = $organization->branches()->where('code', 'MAIN')->sole();
    $boleBranch = $organization->branches()->where('code', 'BOLE')->sole();
    $owner = $organization->staff()->whereRelation('user', 'email', 'owner@demo-gym.test')->sole();
    $receptionist = $organization->staff()->whereRelation('user', 'email', 'frontdesk@demo-gym.test')->sole();
    $accountant = $organization->staff()->whereRelation('user', 'email', 'accounts@demo-gym.test')->sole();

    expect($organization->name)->toBe('Demo Gym')
        ->and($mainBranch->name)->toBe('Main Branch')
        ->and($boleBranch->name)->toBe('Bole Branch')
        ->and($owner->role)->toBe(StaffRole::Owner)
        ->and($owner->works_at_all_branches)->toBeTrue()
        ->and($receptionist->role)->toBe(StaffRole::Receptionist)
        ->and($receptionist->worksAt($mainBranch))->toBeTrue()
        ->and($receptionist->worksAt($boleBranch))->toBeFalse()
        ->and($accountant->role)->toBe(StaffRole::Accountant)
        ->and($accountant->works_at_all_branches)->toBeTrue();
});

it('can run more than once without creating duplicates', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(OrganizationSeeder::class);

    expect(Organization::query()->count())->toBe(1)
        ->and(Branch::query()->acrossOrganizations()->count())->toBe(2)
        ->and(Staff::query()->acrossOrganizations()->count())->toBe(3)
        ->and(User::query()->count())->toBe(3);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Organization::query()->where('slug', 'demo-gym')->exists())->toBeTrue();
});
