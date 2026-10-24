<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;

it('stores the phone number of a user who signs in by SMS in E.164 form', function () {
    $user = User::factory()->withPhone('0922 34 56 78')->create();

    expect($user->refresh()->phone)->toBe('+251922345678')
        ->and($user->email)->toBeNull()
        ->and($user->password)->toBeNull();
});

it('refuses a user with neither an email nor a phone number', function () {
    User::factory()->create(['email' => null]);
})->throws(QueryException::class, 'violates check constraint');

it('refuses a phone number another user already signs in with', function () {
    User::factory()->withPhone('0922 34 56 78')->create();
    User::factory()->withPhone('+251922345678')->create();
})->throws(QueryException::class, 'violates unique constraint');
