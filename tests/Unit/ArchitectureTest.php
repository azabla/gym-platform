<?php

declare(strict_types=1);

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
    ->expect('App\Domains\Shared\Money\Money')
    ->toBeFinal()
    ->toBeReadonly();
