<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Database\Seeders\OrganizationSeeder;

it('creates the demo organization with its main branch', function () {
    $this->seed(OrganizationSeeder::class);

    $organization = Organization::query()->where('slug', 'demo-gym')->sole();
    $branch = $organization->branches()->sole();

    expect($organization->name)->toBe('Demo Gym')
        ->and($branch->code)->toBe('MAIN')
        ->and($branch->name)->toBe('Main Branch');
});

it('can run more than once without creating duplicates', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(OrganizationSeeder::class);

    expect(Organization::query()->count())->toBe(1)
        ->and(Branch::query()->count())->toBe(1);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Organization::query()->where('slug', 'demo-gym')->exists())->toBeTrue();
});
