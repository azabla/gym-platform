<?php

declare(strict_types=1);

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
});

/**
 * PUT the secret key as the gym's Chapa account.
 */
function connectChapa(string $secretKey): TestResponse
{
    return test()->putJson(
        route('api.v1.organizations.chapa-account.update', ['organization' => 'demo-gym']),
        ['secret_key' => $secretKey],
    );
}

it('lets the owner connect the gym\'s own Chapa account, after Chapa accepts the key', function () {
    Http::fake(['api.chapa.co/v1/banks' => Http::response(['message' => 'Banks retrieved', 'data' => []])]);
    $owner = actingAsStaff($this->gym, StaffRole::Owner);

    connectChapa(" CHASECK_TEST-k3y9w8x7v6u5t4s3r2q1p0o9n8m71234\n")
        ->assertCreated()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.mode', 'test')
        ->assertJsonPath('data.key_hint', '1234')
        ->assertJsonPath('data.connected_by.id', $owner->id)
        ->assertDontSee('CHASECK_TEST');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.chapa.co/v1/banks'
        && $request->hasHeader('Authorization', 'Bearer CHASECK_TEST-k3y9w8x7v6u5t4s3r2q1p0o9n8m71234'));

    expect(DB::table('chapa_accounts')->value('secret_key'))->not->toContain('CHASECK')
        ->and(ChapaAccount::query()->acrossOrganizations()->sole()->secret_key)
        ->toBe('CHASECK_TEST-k3y9w8x7v6u5t4s3r2q1p0o9n8m71234');
});

it('replaces the key, and tells a live key from a test key', function () {
    Http::fake(['api.chapa.co/v1/banks' => Http::response(['data' => []])]);
    ChapaAccount::factory()->for($this->gym)->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    connectChapa('CHASECK-l1v3k3y0000000000000000005678')
        ->assertOk()
        ->assertJsonPath('data.mode', 'live')
        ->assertJsonPath('data.key_hint', '5678');

    expect(ChapaAccount::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuses a key Chapa does not accept, and saves nothing', function () {
    Http::fake(['api.chapa.co/v1/banks' => Http::response(['message' => 'Invalid API Key or User doesn\'t exist', 'status' => 'failed'], 401)]);
    actingAsStaff($this->gym, StaffRole::Owner);

    connectChapa('CHASECK_TEST-wrongkey')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['secret_key' => 'Chapa didn\'t accept this key.']);

    expect(ChapaAccount::query()->acrossOrganizations()->exists())->toBeFalse();
});

it('points out the public key without asking Chapa', function () {
    Http::fake();
    actingAsStaff($this->gym, StaffRole::Owner);

    connectChapa('CHAPUBK_TEST-publickey')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['secret_key' => 'That is the public key.']);

    Http::assertNothingSent();
});

it('answers 502 when Chapa does not answer', function () {
    Http::fake(['api.chapa.co/v1/banks' => Http::response(null, 503)]);
    actingAsStaff($this->gym, StaffRole::Owner);

    connectChapa('CHASECK_TEST-k3y')
        ->assertStatus(502)
        ->assertJsonPath('message', 'Chapa didn\'t answer. Try again in a moment.');

    expect(ChapaAccount::query()->acrossOrganizations()->exists())->toBeFalse();
});

it('shows whether the gym\'s Chapa account is connected, but never the key', function () {
    ChapaAccount::factory()->create();
    actingAsStaff($this->gym, StaffRole::Accountant);

    $this->getJson(route('api.v1.organizations.chapa-account.show', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.connected', false)
        ->assertJsonPath('data.key_hint', null);

    $account = ChapaAccount::factory()->for($this->gym)->create();

    $this->getJson(route('api.v1.organizations.chapa-account.show', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.mode', 'test')
        ->assertJsonPath('data.key_hint', $account->key_hint)
        ->assertJsonPath('data.connected_by.id', $account->connected_by_staff_id)
        ->assertDontSee($account->secret_key);
});

it('lets only the owner connect or disconnect, and only owners, managers and accountants see it', function () {
    Http::fake();

    actingAsStaff($this->gym, StaffRole::Manager);
    connectChapa('CHASECK_TEST-k3y')->assertForbidden();
    $this->deleteJson(route('api.v1.organizations.chapa-account.destroy', ['organization' => 'demo-gym']))
        ->assertForbidden();

    foreach ([StaffRole::Receptionist, StaffRole::Trainer] as $role) {
        actingAsStaff($this->gym, $role);
        $this->getJson(route('api.v1.organizations.chapa-account.show', ['organization' => 'demo-gym']))
            ->assertForbidden();
    }

    Http::assertNothingSent();
});

it('lets the owner disconnect Chapa, deleting the key', function () {
    ChapaAccount::factory()->for($this->gym)->create();
    $otherGymsAccount = ChapaAccount::factory()->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->deleteJson(route('api.v1.organizations.chapa-account.destroy', ['organization' => 'demo-gym']))
        ->assertNoContent();

    expect(ChapaAccount::query()->acrossOrganizations()->pluck('id')->all())->toBe([$otherGymsAccount->id]);
});
