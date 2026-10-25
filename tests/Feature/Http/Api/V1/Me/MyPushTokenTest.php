<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Models\PushToken;

beforeEach(function () {
    $this->user = User::factory()->withPhone('0922 34 56 78')->create();
    $this->phone = $this->user->createToken('Tigist\'s phone');
});

it('keeps this phone\'s push token and language, replacing the one it had', function () {
    fromPhone($this->phone->plainTextToken)
        ->putJson(route('api.v1.me.push-token.update'), ['provider' => 'fcm', 'token' => 'fcm-token-1', 'locale' => 'am'])
        ->assertNoContent();
    fromPhone($this->phone->plainTextToken)
        ->putJson(route('api.v1.me.push-token.update'), ['token' => 'fcm-token-2', 'locale' => 'en-US'])
        ->assertNoContent();

    expect(PushToken::query()->sole())
        ->user_id->toBe($this->user->id)
        ->personal_access_token_id->toBe($this->phone->accessToken->id)
        ->token->toBe('fcm-token-2')
        ->locale->toBe('en');
});

it('moves a push token from another sign-in on the same phone', function () {
    $before = User::factory()->withPhone('0911 23 45 67')->create()->createToken('same phone, before');
    PushToken::register($before->accessToken->tokenable_id, $before->accessToken->id, 'fcm-token-1', null);

    fromPhone($this->phone->plainTextToken)
        ->putJson(route('api.v1.me.push-token.update'), ['token' => 'fcm-token-1'])
        ->assertNoContent();

    expect(PushToken::query()->sole()->user_id)->toBe($this->user->id);
});

it('forgets the push token when asked, and when the phone signs out', function () {
    $otherPhone = $this->user->createToken('Tigist\'s tablet');
    PushToken::register($this->user->id, $this->phone->accessToken->id, 'fcm-token-1', null);
    PushToken::register($this->user->id, $otherPhone->accessToken->id, 'fcm-token-2', null);

    fromPhone($this->phone->plainTextToken)->deleteJson(route('api.v1.me.push-token.destroy'))->assertNoContent();
    expect(PushToken::query()->pluck('token')->all())->toBe(['fcm-token-2']);

    fromPhone($otherPhone->plainTextToken)->deleteJson(route('api.v1.auth.tokens.destroy'))->assertNoContent();
    expect(PushToken::query()->count())->toBe(0);
});

it('refuses a missing token, another provider or a made-up language', function () {
    fromPhone($this->phone->plainTextToken)
        ->putJson(route('api.v1.me.push-token.update'), ['provider' => 'apns', 'locale' => 'Amharic please'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token', 'provider', 'locale']);
});

it('needs a signed-in phone', function () {
    $this->putJson(route('api.v1.me.push-token.update'), ['token' => 'fcm-token-1'])->assertUnauthorized();
});
