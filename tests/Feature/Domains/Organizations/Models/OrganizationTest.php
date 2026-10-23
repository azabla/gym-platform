<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('has many branches, and only its own', function () {
    $organization = Organization::factory()->create();
    $ownBranches = Branch::factory()->count(2)->for($organization)->create();
    Branch::factory()->for(Organization::factory())->create();

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
