<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;

it('revokes the token the request was made with', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Front desk tablet')->plainTextToken;

    $this->withToken($token)
        ->deleteJson(route('api.v1.auth.tokens.destroy'))
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertUnauthorized();
});
