<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Exceptions\StaffRefused;
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

it('is limited to the branches it is assigned to, and to none when at all branches', function () {
    $organization = Organization::factory()->create();
    $bole = Branch::factory()->for($organization)->create();
    Branch::factory()->for($organization)->create();
    $staff = Staff::factory()->for($organization)->create();
    actingAsOrganization($organization);

    expect($staff->limitedToBranchIds())->toBeNull();

    $staff->assignToBranches($bole);

    expect($staff->limitedToBranchIds())->toBe([$bole->id]);
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

it('adds a user to the gym at the given branches, or at all of them', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $bole = Branch::factory()->for($organization)->create();

    $receptionist = Staff::add(User::factory()->create(), StaffRole::Receptionist, $bole);
    $manager = Staff::add(User::factory()->create(), StaffRole::Manager);

    expect($receptionist->role)->toBe(StaffRole::Receptionist)
        ->and($receptionist->limitedToBranchIds())->toBe([$bole->id])
        ->and($manager->works_at_all_branches)->toBeTrue();
});

it('refuses to add someone who already works at the gym', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $staff = Staff::factory()->for($organization)->create();

    Staff::add($staff->user, StaffRole::Trainer);
})->throws(StaffRefused::class, 'This person already works at the gym.');

it('gives someone archived their old record back when added again', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $staff = Staff::factory()->for($organization)->create();
    $staff->archive();

    $again = Staff::add($staff->user, StaffRole::Trainer);

    expect($again->id)->toBe($staff->id)
        ->and($again->trashed())->toBeFalse()
        ->and($again->role)->toBe(StaffRole::Trainer)
        ->and(Staff::query()->withTrashed()->count())->toBe(1);
});

it('puts an owner at all branches, whatever branches are given', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $bole = Branch::factory()->for($organization)->create();
    $staff = Staff::factory()->for($organization)->create();
    $staff->assignToBranches($bole);

    $staff->reassign(StaffRole::Owner, $bole);

    expect($staff->fresh()->role)->toBe(StaffRole::Owner)
        ->and($staff->fresh()->works_at_all_branches)->toBeTrue();
});

it('never demotes or archives the gym\'s last owner', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $owner = Staff::factory()->for($organization)->owner()->create();

    expect(fn () => $owner->reassign(StaffRole::Manager))->toThrow(StaffRefused::class, 'The gym must keep at least one owner.')
        ->and(fn () => $owner->archive())->toThrow(StaffRefused::class)
        ->and($owner->fresh()->role)->toBe(StaffRole::Owner)
        ->and($owner->fresh()->trashed())->toBeFalse();

    $secondOwner = Staff::factory()->for($organization)->owner()->create();
    $owner->archive();

    expect($owner->trashed())->toBeTrue()
        ->and(fn () => $secondOwner->archive())->toThrow(StaffRefused::class);
});

it('covers staff who work only at its own branches', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $bole = Branch::factory()->for($organization)->create();
    $piassa = Branch::factory()->for($organization)->create();
    $atBole = Staff::factory()->for($organization)->create();
    $atBole->assignToBranches($bole);
    $atBoth = Staff::factory()->for($organization)->create();
    $atBoth->assignToBranches($bole, $piassa);
    $everywhere = Staff::factory()->for($organization)->create();

    expect($everywhere->covers($atBoth))->toBeTrue()
        ->and($atBoth->covers($atBole))->toBeTrue()
        ->and($atBole->covers($atBoth))->toBeFalse()
        ->and($atBoth->covers($everywhere))->toBeFalse();
});
