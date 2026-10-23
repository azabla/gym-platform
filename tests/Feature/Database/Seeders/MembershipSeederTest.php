<?php

declare(strict_types=1);

use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Database\Seeders\MemberSeeder;
use Database\Seeders\MembershipSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PlanSeeder;

it('creates the demo memberships', function () {
    $this->seed([OrganizationSeeder::class, MemberSeeder::class, PlanSeeder::class, MembershipSeeder::class]);

    $organization = actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());

    $statuses = Membership::query()->with('member')->orderBy('id')->get()
        ->mapWithKeys(fn (Membership $membership) => [
            $membership->member->full_name => $membership->statusOn($organization->today()),
        ])
        ->all();

    expect($statuses)->toBe([
        'Abebe Kebede' => MembershipStatus::Active,
        'Tigist Haile' => MembershipStatus::Expired,
    ]);
});

it('can run more than once without creating duplicates', function () {
    $this->seed([OrganizationSeeder::class, MemberSeeder::class, PlanSeeder::class, MembershipSeeder::class]);
    $this->seed(MembershipSeeder::class);

    expect(Membership::query()->acrossOrganizations()->count())->toBe(2);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Membership::query()->acrossOrganizations()->count())->toBe(2);
});
