<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\PasswordLinkNotification;
use App\Domains\Identity\Notifications\StaffAddedNotification;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Onboarding\NewGym;
use App\Domains\Shared\Language\Language;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    Notification::fake();
});

/**
 * Bole Fitness, as the admin panel or gyms:create would open it.
 */
function boleFitnessGym(Language $language = Language::English): NewGym
{
    return new NewGym('Bole Fitness', 'bole-fitness', 'Africa/Addis_Ababa', $language, 'Bole Branch', 'Meron Assefa', PhoneNumber::of('0911223344'));
}

it('opens a gym with its first branch, message language and owner, who gets an invite', function () {
    $owner = Organization::open(boleFitnessGym(Language::Amharic));

    $gym = actingAsOrganization(Organization::query()->where('slug', 'bole-fitness')->sole());

    expect($owner->role)->toBe(StaffRole::Owner)
        ->and($gym->messageLanguage())->toBe(Language::Amharic)
        ->and($gym->branches()->sole()->only(['name', 'code']))->toBe(['name' => 'Bole Branch', 'code' => 'MAIN'])
        ->and(Staff::query()->sole()->user->name)->toBe('Meron Assefa');
    Notification::assertSentTo($owner->user, PasswordLinkNotification::class);
});

it('makes an owner who already signs in elsewhere the owner, telling them they were added', function () {
    User::factory()->create(['phone' => '+251911223344', 'password' => 'secret-password']);

    $owner = Organization::open(boleFitnessGym());

    Notification::assertSentTo($owner->user, StaffAddedNotification::class);
    expect(User::query()->where('phone', '+251911223344')->count())->toBe(1);
});

it('checks a new gym\'s slug, language and phone', function (array $changes, string $field) {
    Organization::factory()->create(['slug' => 'demo-gym']);
    $input = [
        'name' => 'Bole Fitness', 'slug' => 'bole-fitness', 'timezone' => 'Africa/Addis_Ababa', 'message_language' => 'en',
        'branch' => 'Main Branch', 'owner_name' => 'Meron Assefa', 'owner_phone' => '0911223344', ...$changes,
    ];

    expect(Validator::make($input, NewGym::rules())->errors()->keys())->toBe([$field]);
})->with([
    'taken slug' => [['slug' => 'demo-gym'], 'slug'],
    'the admin panel\'s address' => [['slug' => 'admin'], 'slug'],
    'unknown language' => [['message_language' => 'fr'], 'message_language'],
    'not a phone' => [['owner_phone' => '12'], 'owner_phone'],
]);
