<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('lets one user work for several organizations with different roles', function () {
    $user = User::factory()->create();
    Staff::factory()->for($user)->for(Organization::factory())->owner()->create();
    Staff::factory()->for($user)->for(Organization::factory())->create(['role' => StaffRole::Trainer]);

    $roles = Staff::query()->acrossOrganizations()->whereBelongsTo($user)->pluck('role')->all();

    expect($roles)->toHaveCount(2)->toContain(StaffRole::Owner, StaffRole::Trainer);
});

it('allows only one staff record per user per organization', function () {
    $staff = Staff::factory()->create();

    Staff::factory()->for($staff->user)->for($staff->organization)->create();
})->throws(UniqueConstraintViolationException::class);

it('works at every branch of its organization when assigned to all branches', function () {
    $organization = Organization::factory()->create();
    $staff = Staff::factory()->for($organization)->create();
    $branchOpenedLater = Branch::factory()->for($organization)->create();
    $otherOrganizationsBranch = Branch::factory()->create();

    expect($staff->worksAt($branchOpenedLater))->toBeTrue()
        ->and($staff->worksAt($otherOrganizationsBranch))->toBeFalse();
});

it('works only at the branches it is assigned to', function () {
    $organization = Organization::factory()->create();
    $bole = Branch::factory()->for($organization)->create();
    $piassa = Branch::factory()->for($organization)->create();
    $staff = Staff::factory()->for($organization)->create();
    actingAsOrganization($organization);

    $staff->assignToBranches($bole);

    expect($staff->works_at_all_branches)->toBeFalse()
        ->and($staff->worksAt($bole))->toBeTrue()
        ->and($staff->worksAt($piassa))->toBeFalse();
});

it('drops its branch assignments when assigned back to all branches', function () {
    $organization = Organization::factory()->create();
    $bole = Branch::factory()->for($organization)->create();
    $staff = Staff::factory()->for($organization)->create();
    $staff->assignToBranches($bole);
    actingAsOrganization($organization);

    $staff->assignToAllBranches();

    expect($staff->works_at_all_branches)->toBeTrue()
        ->and($staff->branches()->count())->toBe(0);
});

it('keeps owners assigned to all branches', function () {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();
    $owner = Staff::factory()->for($organization)->owner()->create();

    $owner->assignToBranches($branch);
})->throws(QueryException::class, 'staff_owner_works_at_all_branches');

it('cannot be assigned to a branch of another organization', function () {
    $staff = Staff::factory()->create();
    $otherOrganizationsBranch = Branch::factory()->create();

    $staff->assignToBranches($otherOrganizationsBranch);
})->throws(QueryException::class, 'violates foreign key constraint');
