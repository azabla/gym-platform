<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\ChapaMode;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Pages\OnlinePayments;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
});

it('lets the owner connect the gym\'s Chapa account, after Chapa accepts the key', function () {
    Http::fake(['api.chapa.co/v1/banks' => Http::response(['message' => 'Banks retrieved', 'data' => []])]);
    $owner = actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(OnlinePayments::class)
        ->assertSee('Not connected')
        ->callAction('connect', ['secret_key' => " CHASECK_TEST-k3y9w8x7v6u5t4s3r2q1p0o9n8m71234\n"])
        ->assertHasNoActionErrors()
        ->assertNotified('Chapa is connected.');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer CHASECK_TEST-k3y9w8x7v6u5t4s3r2q1p0o9n8m71234'));
    $account = ChapaAccount::query()->sole();
    expect($account->secret_key)->toBe('CHASECK_TEST-k3y9w8x7v6u5t4s3r2q1p0o9n8m71234')
        ->and($account->mode)->toBe(ChapaMode::Test)
        ->and($account->connected_by_staff_id)->toBe($owner->id);

    Livewire::test(OnlinePayments::class)
        ->assertSee('…1234')
        ->assertDontSee('CHASECK_TEST')
        ->assertActionHasLabel('connect', 'Replace key');
});

it('refuses the public key and a key Chapa doesn\'t accept', function (string $secretKey, string $message) {
    Http::fake(['api.chapa.co/v1/banks' => Http::response(['message' => 'Invalid API Key'], 401)]);
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(OnlinePayments::class)
        ->callAction('connect', ['secret_key' => $secretKey])
        ->assertHasActionErrors(['secret_key' => $message]);

    expect(ChapaAccount::query()->exists())->toBeFalse();
})->with([
    'public key' => ['CHAPUBK_TEST-abc', 'That is the public key. Paste the secret key, which starts with CHASECK.'],
    'refused' => ['CHASECK_TEST-wrong', 'Chapa didn\'t accept this key. Copy the whole secret key from your Chapa dashboard.'],
]);

it('saves nothing when Chapa doesn\'t answer', function () {
    Http::fake(['api.chapa.co/v1/banks' => Http::response([], 503)]);
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(OnlinePayments::class)
        ->callAction('connect', ['secret_key' => 'CHASECK_TEST-k3y'])
        ->assertHasActionErrors(['secret_key' => 'Chapa didn\'t answer. Try again in a moment.']);

    expect(ChapaAccount::query()->exists())->toBeFalse();
});

it('lets the owner disconnect, deleting the key', function () {
    ChapaAccount::factory()->for($this->gym)->create();
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(OnlinePayments::class)
        ->callAction('disconnect')
        ->assertNotified('Chapa is disconnected.');

    expect(ChapaAccount::query()->exists())->toBeFalse();
});

it('shows managers and accountants the account, without changing it', function (StaffRole $role) {
    ChapaAccount::factory()->for($this->gym)->create();
    actingAsStaffInPanel($this->gym, $role);

    Livewire::test(OnlinePayments::class)
        ->assertOk()
        ->assertActionHidden('connect')
        ->assertActionHidden('disconnect');
})->with([StaffRole::Manager, StaffRole::Accountant]);

it('keeps the page from receptionists and trainers', function (StaffRole $role) {
    actingAsStaffInPanel($this->gym, $role);

    $this->get(route('filament.staff.pages.online-payments', ['tenant' => 'demo-gym']))->assertForbidden();
})->with([StaffRole::Receptionist, StaffRole::Trainer]);
