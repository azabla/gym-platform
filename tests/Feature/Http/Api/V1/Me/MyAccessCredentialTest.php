<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->tigist = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->user = User::factory()->withPhone('0922 34 56 78')->create();
});

it('issues this phone\'s QR credential, with its secret only once', function () {
    $phone = $this->user->createToken('Tigist\'s phone')->plainTextToken;

    $issued = fromPhone($phone)
        ->postJson(route('api.v1.me.gyms.access-credential.store', ['organization' => 'demo-gym']))
        ->assertCreated()
        ->assertJsonPath('data.algorithm', 'GQ1')
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    expect($issued['credential_id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and(strlen((string) base64_decode($issued['secret'], true)))->toBe(32);

    $credential = AccessCredential::query()->acrossOrganizations()->sole();

    expect($credential->publicId())->toBe($issued['credential_id'])
        ->and($credential->rawSecret())->toBe(base64_decode($issued['secret'], true))
        ->and($credential->member_id)->toBe($this->tigist->id);

    fromPhone($phone)
        ->getJson(route('api.v1.me.gyms.access-credential.show', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.credential_id', $issued['credential_id'])
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.secret');
});

it('revokes the old phone\'s credential when another phone issues one', function () {
    $oldPhone = $this->user->createToken('Old phone')->plainTextToken;
    $newPhone = $this->user->createToken('New phone')->plainTextToken;

    fromPhone($oldPhone)->postJson(route('api.v1.me.gyms.access-credential.store', ['organization' => 'demo-gym']))->assertCreated();
    fromPhone($newPhone)->postJson(route('api.v1.me.gyms.access-credential.store', ['organization' => 'demo-gym']))->assertCreated();

    fromPhone($oldPhone)
        ->getJson(route('api.v1.me.gyms.access-credential.show', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.revoked_reason', 'replaced');

    fromPhone($newPhone)
        ->getJson(route('api.v1.me.gyms.access-credential.show', ['organization' => 'demo-gym']))
        ->assertJsonPath('data.status', 'active');

    expect(AccessCredential::query()->acrossOrganizations()->whereNull('revoked_at')->count())->toBe(1);
});

it('answers 404 when this phone has no credential', function () {
    $phone = $this->user->createToken('Tigist\'s phone')->plainTextToken;
    AccessCredential::factory()->create(['member_id' => $this->tigist->id, 'user_id' => $this->user->id]);

    fromPhone($phone)
        ->getJson(route('api.v1.me.gyms.access-credential.show', ['organization' => 'demo-gym']))
        ->assertNotFound();
});

it('revokes the phone\'s credential when the member signs out on it', function () {
    $phone = $this->user->createToken('Tigist\'s phone')->plainTextToken;
    fromPhone($phone)->postJson(route('api.v1.me.gyms.access-credential.store', ['organization' => 'demo-gym']))->assertCreated();

    fromPhone($phone)->deleteJson(route('api.v1.auth.tokens.destroy'))->assertNoContent();

    $credential = AccessCredential::query()->acrossOrganizations()->sole();

    expect($credential->revoked_reason)->toBe(CredentialRevocation::SignedOut)
        ->and($credential->revoked_at)->not->toBeNull();
});

it('issues credentials only at a gym where the user is a member', function () {
    Organization::factory()->create(['slug' => 'bole-fitness']);
    $phone = $this->user->createToken('Tigist\'s phone')->plainTextToken;

    fromPhone($phone)
        ->postJson(route('api.v1.me.gyms.access-credential.store', ['organization' => 'bole-fitness']))
        ->assertNotFound();

    expect(AccessCredential::query()->acrossOrganizations()->exists())->toBeFalse();
});
