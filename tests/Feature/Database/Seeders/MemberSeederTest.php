<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Organization;
use Database\Seeders\MemberSeeder;
use Database\Seeders\OrganizationSeeder;

it('creates the demo members', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(MemberSeeder::class);

    actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());

    expect(Member::query()->orderBy('full_name')->pluck('full_name')->all())
        ->toBe(['Abebe Kebede', 'Hana Girma', 'Tigist Haile']);
});

it('can run more than once without creating duplicates', function () {
    $this->seed(OrganizationSeeder::class);
    $this->seed(MemberSeeder::class);
    $this->seed(MemberSeeder::class);

    expect(Member::query()->acrossOrganizations()->count())->toBe(3);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Member::query()->acrossOrganizations()->count())->toBe(3);
});
