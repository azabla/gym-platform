<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Identity\Enums\SignInApp;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\SignIns;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'UTC'));
    $this->user = User::factory()->create();
});

it('tags each sign-in with its app', function () {
    $staff = SignIns::start($this->user, 'Front desk tablet', SignInApp::Staff);
    $member = SignIns::start($this->user, 'Hana\'s phone', SignInApp::Members);

    expect($staff->accessToken->refresh()->getAttribute('app'))->toBe('staff')
        ->and($member->accessToken->refresh()->getAttribute('app'))->toBe('members');
});

it('lets a staff sign-in unused for 30 days expire, a members one after 90, and use keeps either alive', function (SignInApp $app, int $stillGood, int $expired) {
    $token = SignIns::start($this->user, 'Phone', $app)->plainTextToken;

    $this->travel($stillGood)->days();
    fromPhone($token)->getJson('/api/v1/me')->assertOk();

    $this->travel($stillGood)->days();
    fromPhone($token)->getJson('/api/v1/me')->assertOk();

    $this->travel($expired)->days();
    fromPhone($token)->getJson('/api/v1/me')->assertUnauthorized();
})->with([
    'staff app' => [SignInApp::Staff, 29, 31],
    'members app' => [SignInApp::Members, 89, 91],
]);

it('ends unused sign-ins every night, revoking the QR credentials issued under them, and keeps the rest', function () {
    $old = SignIns::start($this->user, 'Old phone', SignInApp::Staff);
    $credential = AccessCredential::factory()->create(['personal_access_token_id' => $old->accessToken->id]);
    $this->travel(31)->days();
    $fresh = SignIns::start($this->user, 'New phone', SignInApp::Staff);

    $this->artisan('sign-ins:end-unused')->expectsOutputToContain('Ended 1 unused sign-in.')->assertSuccessful();

    expect(PersonalAccessToken::query()->pluck('id')->all())->toBe([$fresh->accessToken->id])
        ->and($credential->refresh()->revoked_reason)->toBe(CredentialRevocation::SignedOut);
});

it('ends every sign-in a person has for one app, leaving the other', function () {
    SignIns::start($this->user, 'Desk', SignInApp::Staff);
    SignIns::start($this->user, 'Tablet', SignInApp::Staff);
    $member = SignIns::start($this->user, 'Phone', SignInApp::Members);

    expect(SignIns::endEverywhere($this->user, SignInApp::Staff))->toBe(2)
        ->and(SignIns::of($this->user)->modelKeys())->toBe([$member->accessToken->id]);
});
