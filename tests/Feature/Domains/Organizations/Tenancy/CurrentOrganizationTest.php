<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\Exceptions\NoCurrentOrganization;

it('throws when no organization is set', function () {
    app(CurrentOrganization::class)->get();
})->throws(NoCurrentOrganization::class);

it('runs code as another organization and restores the previous one', function () {
    $downtownGym = actingAsOrganization(Organization::factory()->create());
    $uptownGym = Organization::factory()->create();
    $currentOrganization = app(CurrentOrganization::class);

    $idInside = $currentOrganization->run($uptownGym, fn () => $currentOrganization->id());

    expect($idInside)->toBe($uptownGym->id)
        ->and($currentOrganization->id())->toBe($downtownGym->id);
});

it('restores the previous organization even when the code throws', function () {
    $currentOrganization = app(CurrentOrganization::class);
    $organization = Organization::factory()->create();

    expect(fn () => $currentOrganization->run($organization, function () {
        throw new RuntimeException('Something went wrong.');
    }))->toThrow(RuntimeException::class);

    expect($currentOrganization->has())->toBeFalse();
});

it('is forgotten when a new request or queue job starts', function () {
    actingAsOrganization(Organization::factory()->create());

    app()->forgetScopedInstances();

    expect(app(CurrentOrganization::class)->has())->toBeFalse();
});
