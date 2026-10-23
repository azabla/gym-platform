<?php

declare(strict_types=1);

use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Organization;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PlanSeeder;

it('creates the demo plans', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(PlanSeeder::class);

    actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());

    expect(Plan::query()->orderBy('price_minor')->pluck('name')->all())
        ->toBe(['Day Pass', 'Monthly', '3 Months', 'Yearly']);
});

it('can run more than once without creating duplicates', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->acrossOrganizations()->count())->toBe(4);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Plan::query()->acrossOrganizations()->count())->toBe(4);
});
