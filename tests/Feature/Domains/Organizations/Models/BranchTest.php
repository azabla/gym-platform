<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;

it('belongs to an organization', function () {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    expect($branch->organization->is($organization))->toBeTrue();
});

it('requires a unique code within an organization', function () {
    $organization = Organization::factory()->create();
    Branch::factory()->for($organization)->create(['code' => 'MAIN']);

    Branch::factory()->for($organization)->create(['code' => 'MAIN']);
})->throws(UniqueConstraintViolationException::class);

it('lets different organizations use the same code', function () {
    Branch::factory()->for(Organization::factory())->create(['code' => 'MAIN']);
    Branch::factory()->for(Organization::factory())->create(['code' => 'MAIN']);

    expect(Branch::query()->where('code', 'MAIN')->count())->toBe(2);
});

it('cannot be moved to another organization by mass assignment', function () {
    $organization = Organization::factory()->create();
    $branch = Branch::factory()->for($organization)->create();

    $branch->update(['organization_id' => Organization::factory()->create()->id]);

    expect($branch->refresh()->organization->is($organization))->toBeTrue();
});
