<?php

declare(strict_types=1);

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;

it('makes someone new a platform admin, with a link to set their password', function () {
    $this->artisan('platform:admins', ['action' => 'add', '--phone' => '0911 22 33 44', '--name' => 'Selam Tadesse'])
        ->expectsOutputToContain('Selam Tadesse is now a platform admin. Send them this link')
        ->expectsOutputToContain('/password/')
        ->assertSuccessful();

    $selam = User::query()->where('phone', '+251911223344')->sole();

    expect($selam->is_platform_admin)->toBeTrue()
        ->and($selam->name)->toBe('Selam Tadesse')
        ->and(PasswordLink::query()->where('user_id', $selam->id)->exists())->toBeTrue();
});

it('makes someone who already signs in a platform admin, keeping their password', function () {
    $owner = User::factory()->create(['phone' => '+251911223344', 'password' => 'secret-password']);

    $this->artisan('platform:admins', ['action' => 'add', '--phone' => '+251911223344'])
        ->expectsOutputToContain('signs in at /admin with the password they have')
        ->assertSuccessful();

    expect($owner->refresh()->is_platform_admin)->toBeTrue()
        ->and(PasswordLink::query()->exists())->toBeFalse();
});

it('removes and lists platform admins', function () {
    $selam = User::factory()->create(['name' => 'Selam Tadesse', 'phone' => '+251911223344']);
    $selam->makePlatformAdmin();
    User::factory()->create(['name' => 'Abebe Kebede', 'phone' => '+251922334455']);

    $this->artisan('platform:admins', ['action' => 'list'])
        ->expectsOutputToContain('Selam Tadesse')
        ->doesntExpectOutputToContain('Abebe Kebede')
        ->assertSuccessful();

    $this->artisan('platform:admins', ['action' => 'remove', '--phone' => '0911223344'])
        ->expectsOutputToContain('Selam Tadesse is no longer a platform admin.')
        ->assertSuccessful();

    expect($selam->refresh()->is_platform_admin)->toBeFalse();
});

it('refuses a bad phone, an unknown admin and an unknown action', function () {
    $this->artisan('platform:admins', ['action' => 'add', '--phone' => '12', '--name' => 'X'])->assertFailed();
    $this->artisan('platform:admins', ['action' => 'remove', '--phone' => '0911223344'])
        ->expectsOutputToContain('No platform admin has this phone.')
        ->assertFailed();
    $this->artisan('platform:admins', ['action' => 'promote'])->assertFailed();

    expect(User::query()->where('is_platform_admin', true)->exists())->toBeFalse();
});

it('never makes anyone a platform admin by default', function () {
    expect(User::factory()->create()->is_platform_admin)->toBeFalse();
});
