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
    $boleMonthly = Plan::query()->where('name', 'Bole Monthly')->sole();

    expect(Plan::query()->orderBy('price_minor')->pluck('freeze_days_allowed', 'name')->all())
        ->toBe(['Day Pass' => 0, 'Bole Monthly' => 7, 'Monthly' => 7, '3 Months' => 14, 'Yearly' => 30])
        ->and($boleMonthly->available_at_all_branches)->toBeFalse()
        ->and($boleMonthly->branches->pluck('code')->all())->toBe(['BOLE']);
});

it('can run more than once without creating duplicates', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->acrossOrganizations()->count())->toBe(5);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Plan::query()->acrossOrganizations()->count())->toBe(5);
});
