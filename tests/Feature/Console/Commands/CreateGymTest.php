<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\PasswordLinkNotification;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

/**
 * The options for Bole Fitness, with the given ones changed.
 *
 * @param  array<string, string>  $changes
 * @return array<string, string>
 */
function boleFitness(array $changes = []): array
{
    return [
        '--name' => 'Bole Fitness',
        '--slug' => 'bole-fitness',
        '--owner-name' => 'Meron Assefa',
        '--owner-phone' => '0911 22 33 44',
        ...$changes,
    ];
}

it('creates a gym with its first branch and its owner, who gets an invite by SMS', function () {
    $this->artisan('gyms:create', boleFitness())
        ->expectsOutputToContain('Created Bole Fitness (bole-fitness) with Main Branch.')
        ->assertSuccessful();

    $gym = actingAsOrganization(Organization::query()->where('slug', 'bole-fitness')->sole());
    $owner = Staff::query()->sole();
    $meron = User::query()->where('phone', '+251911223344')->sole();

    expect($gym->name)->toBe('Bole Fitness')
        ->and($gym->timezone)->toBe('Africa/Addis_Ababa')
        ->and($gym->branches()->sole()->name)->toBe('Main Branch')
        ->and($owner->role)->toBe(StaffRole::Owner)
        ->and($owner->user_id)->toBe($meron->id)
        ->and($meron->name)->toBe('Meron Assefa');
    Notification::assertSentTo($meron, PasswordLinkNotification::class, fn (PasswordLinkNotification $notification): bool => str_contains($notification->toSms($meron), 'Bole Fitness added you'));
});

it('takes a branch name and time zone', function () {
    $this->artisan('gyms:create', boleFitness(['--branch' => 'Bole Branch', '--timezone' => 'Africa/Nairobi']))->assertSuccessful();

    $gym = actingAsOrganization(Organization::query()->where('slug', 'bole-fitness')->sole());

    expect($gym->timezone)->toBe('Africa/Nairobi')
        ->and($gym->branches()->sole()->name)->toBe('Bole Branch');
});

it('refuses a slug that is taken, reserved or badly formed, and creates nothing', function (string $slug) {
    Organization::factory()->create(['slug' => 'demo-gym']);

    $this->artisan('gyms:create', boleFitness(['--slug' => $slug]))->assertFailed();

    expect(Organization::query()->count())->toBe(1)
        ->and(User::query()->where('phone', '+251911223344')->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['demo-gym', 'login', 'Bole Fitness', '-bole']);

it('refuses a phone or time zone it can\'t read', function () {
    $this->artisan('gyms:create', boleFitness(['--owner-phone' => '12']))->assertFailed();
    $this->artisan('gyms:create', boleFitness(['--timezone' => 'Addis Ababa']))->assertFailed();

    expect(Organization::query()->count())->toBe(0);
});

it('asks for what it isn\'t given', function () {
    $this->artisan('gyms:create')
        ->expectsQuestion('The gym\'s name', 'Bole Fitness')
        ->expectsQuestion('Its slug, used in addresses', 'bole-fitness')
        ->expectsQuestion('The owner\'s name', 'Meron Assefa')
        ->expectsQuestion('The owner\'s phone', '0911 22 33 44')
        ->assertSuccessful();

    expect(Organization::query()->where('slug', 'bole-fitness')->exists())->toBeTrue();
});
