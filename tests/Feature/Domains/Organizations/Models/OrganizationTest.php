<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('has many branches, and only its own', function () {
    $organization = Organization::factory()->create();
    $ownBranches = Branch::factory()->count(2)->for($organization)->create();
    Branch::factory()->for(Organization::factory())->create();

    actingAsOrganization($organization);

    expect($organization->branches->modelKeys())->toEqualCanonicalizing($ownBranches->modelKeys());
});

it('requires a unique slug', function () {
    Organization::factory()->create(['slug' => 'demo-gym']);

    Organization::factory()->create(['slug' => 'demo-gym']);
})->throws(UniqueConstraintViolationException::class);

it('cannot be deleted while it still has branches', function () {
    $organization = Organization::factory()->create();
    Branch::factory()->for($organization)->create();

    $organization->delete();
})->throws(QueryException::class, 'violates foreign key constraint');

it('is in the Addis Ababa time zone unless told otherwise', function () {
    $organization = Organization::factory()->create();

    expect($organization->timezone)->toBe('Africa/Addis_Ababa')
        ->and($organization->refresh()->timezone)->toBe('Africa/Addis_Ababa');
});

it('knows the date where the gym is', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 22:30:00', 'UTC'));

    $addisAbabaGym = Organization::factory()->create();
    $utcGym = Organization::factory()->create(['timezone' => 'UTC']);

    expect($addisAbabaGym->today()->toDateString())->toBe('2026-10-24')
        ->and($utcGym->today()->toDateString())->toBe('2026-10-23');
});
