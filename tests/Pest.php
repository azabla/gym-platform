<?php

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Act as the given organization (gym) for the rest of the test.
 */
function actingAsOrganization(Organization $organization): Organization
{
    app(CurrentOrganization::class)->set($organization);

    return $organization;
}

/**
 * Sign in to the API as a new staff member of the gym, limited to the given
 * branches if any are passed.
 */
function actingAsStaff(Organization $organization, StaffRole $role, Branch ...$onlyAtBranches): Staff
{
    $staff = Staff::factory()->for($organization)->create(['role' => $role]);

    if ($onlyAtBranches !== []) {
        $staff->assignToBranches(...$onlyAtBranches);
    }

    Sanctum::actingAs($staff->user);

    return $staff;
}
