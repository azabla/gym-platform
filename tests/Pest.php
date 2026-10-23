<?php

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
