<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;

/*
 * Architecture tests turn our conventions into rules the test suite enforces.
 * A convention that only lives in someone's head will eventually be broken.
 */

arch('all domain code declares strict types')
    ->expect('App\Domains')
    ->toUseStrictTypes();

arch('no debugging helpers are left in the codebase')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('the Shared kernel is plain PHP with no framework dependency')
    ->expect('App\Domains\Shared')
    ->not->toUse('Illuminate');

arch('value objects are final and readonly')
    ->expect([
        'App\Domains\Shared\Money\Money',
        'App\Domains\Shared\PhoneNumber\PhoneNumber',
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('organization-owned models are scoped to the current organization')
    ->expect([
        'App\Domains\Organizations\Models',
        'App\Domains\Members\Models',
        'App\Domains\Memberships\Models',
    ])
    ->toUseTrait(BelongsToOrganization::class)
    ->ignoring(Organization::class);

arch('the Identity domain does not depend on Organizations, Members or Memberships')
    ->expect('App\Domains\Identity')
    ->not->toUse(['App\Domains\Organizations', 'App\Domains\Members', 'App\Domains\Memberships']);

arch('the Organizations domain does not depend on Members or Memberships')
    ->expect('App\Domains\Organizations')
    ->not->toUse(['App\Domains\Members', 'App\Domains\Memberships']);

arch('the Members domain does not depend on Memberships')
    ->expect('App\Domains\Members')
    ->not->toUse('App\Domains\Memberships');

arch('domains do not depend on the HTTP layer')
    ->expect('App\Domains')
    ->not->toUse('App\Http');
