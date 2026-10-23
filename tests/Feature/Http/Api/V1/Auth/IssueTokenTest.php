<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

it('issues a token for valid credentials', function () {
    $user = User::factory()->create(['email' => 'abebe@example.com']);

    $response = $this->postJson(route('api.v1.auth.tokens.store'), [
        'email' => 'abebe@example.com',
        'password' => 'password',
        'device_name' => 'Front desk tablet',
    ]);

    $response->assertCreated()->assertJsonPath('data.user.email', 'abebe@example.com');

    $this->withToken($response->json('data.token'))
        ->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);
});

it('accepts the email in any letter case', function () {
    User::factory()->create(['email' => 'abebe@example.com']);

    $this->postJson(route('api.v1.auth.tokens.store'), [
        'email' => 'Abebe@Example.com',
        'password' => 'password',
        'device_name' => 'Front desk tablet',
    ])->assertCreated();
});

it('rejects a wrong password without issuing a token', function () {
    User::factory()->create(['email' => 'abebe@example.com']);

    $this->postJson(route('api.v1.auth.tokens.store'), [
        'email' => 'abebe@example.com',
        'password' => 'wrong-password',
        'device_name' => 'Front desk tablet',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('gives an unknown email the same answer as a wrong password', function () {
    $this->postJson(route('api.v1.auth.tokens.store'), [
        'email' => 'nobody@example.com',
        'password' => 'password',
        'device_name' => 'Front desk tablet',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email' => __('auth.failed')]);
});

it('requires an email, a password and a device name', function () {
    $this->postJson(route('api.v1.auth.tokens.store'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

it('allows five sign-in attempts a minute', function () {
    $attempt = fn () => $this->postJson(route('api.v1.auth.tokens.store'), [
        'email' => 'abebe@example.com',
        'password' => 'wrong-password',
        'device_name' => 'Front desk tablet',
    ]);

    foreach (range(1, 5) as $ignored) {
        $attempt()->assertUnprocessable();
    }

    $attempt()->assertTooManyRequests();
});
