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
        'App\Domains\Memberships\Discount\Discount',
        'App\Domains\Billing\Balances\MembershipBalance',
        'App\Domains\Billing\Chapa\ChapaTransaction',
        'App\Domains\Attendance\Access\AccessDecision',
        'App\Domains\Notifications\Push\PushMessage',
        'App\Domains\Attendance\Credentials\Gq1Payload',
    ])
    ->toBeFinal()
    ->toBeReadonly();

arch('organization-owned models are scoped to the current organization')
    ->expect([
        'App\Domains\Organizations\Models',
        'App\Domains\Members\Models',
        'App\Domains\Memberships\Models',
        'App\Domains\Billing\Models',
        'App\Domains\Attendance\Models',
    ])
    ->toUseTrait(BelongsToOrganization::class)
    ->ignoring(Organization::class);

arch('the Identity domain does not depend on Organizations, Members, Memberships, Billing or Attendance')
    ->expect('App\Domains\Identity')
    ->not->toUse(['App\Domains\Organizations', 'App\Domains\Members', 'App\Domains\Memberships', 'App\Domains\Billing', 'App\Domains\Attendance']);

arch('the Organizations domain does not depend on Members, Memberships, Billing or Attendance')
    ->expect('App\Domains\Organizations')
    ->not->toUse(['App\Domains\Members', 'App\Domains\Memberships', 'App\Domains\Billing', 'App\Domains\Attendance']);

arch('the Members domain does not depend on Memberships or Billing')
    ->expect('App\Domains\Members')
    ->not->toUse(['App\Domains\Memberships', 'App\Domains\Billing']);

arch('the Memberships domain does not depend on Billing')
    ->expect('App\Domains\Memberships')
    ->not->toUse('App\Domains\Billing');

arch('Members, Memberships and Billing do not depend on Attendance')
    ->expect(['App\Domains\Members', 'App\Domains\Memberships', 'App\Domains\Billing'])
    ->not->toUse('App\Domains\Attendance');

arch('the Notifications domain depends on no other domain but Shared')
    ->expect('App\Domains\Notifications')
    ->not->toUse(['App\Domains\Identity', 'App\Domains\Organizations', 'App\Domains\Members', 'App\Domains\Memberships', 'App\Domains\Billing', 'App\Domains\Attendance']);

arch('domains do not depend on the HTTP layer')
    ->expect('App\Domains')
    ->not->toUse('App\Http');
