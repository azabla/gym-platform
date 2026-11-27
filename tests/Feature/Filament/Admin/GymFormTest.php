<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\PasswordLinkNotification;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Language\Language;
use App\Filament\Admin\AdminUser;
use App\Filament\Admin\Resources\Gyms\GymResource;
use App\Filament\Admin\Resources\Gyms\Pages\CreateGym;
use App\Filament\Admin\Resources\Gyms\Pages\EditGym;
use App\Filament\Admin\Resources\Gyms\Pages\ViewGym;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();

    $admin = User::factory()->create(['phone' => '+251911000000']);
    $admin->makePlatformAdmin();
    $this->actingAs(AdminUser::query()->findOrFail($admin->id), 'admin_panel');
    Filament::setCurrentPanel('admin');
});

it('opens a gym with its first branch and owner, who gets an SMS to set their password', function () {
    Livewire::test(CreateGym::class)
        ->fillForm([
            'name' => 'Bole Fitness',
            'slug' => 'bole-fitness',
            'timezone' => 'Africa/Addis_Ababa',
            'message_language' => 'am',
            'branch' => 'Bole Branch',
            'owner_name' => 'Meron Assefa',
            'owner_phone' => '0911 22 33 44',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Gym opened. The owner has an SMS to set their password.');

    $gym = actingAsOrganization(Organization::query()->where('slug', 'bole-fitness')->sole());
    $owner = Staff::query()->sole();

    expect($gym->messageLanguage())->toBe(Language::Amharic)
        ->and($gym->branches()->sole()->name)->toBe('Bole Branch')
        ->and($owner->role)->toBe(StaffRole::Owner);
    Notification::assertSentTo($owner->user, PasswordLinkNotification::class);
});

it('suggests the address from the name, and refuses one that is taken or reserved', function (string $slug) {
    Organization::factory()->create(['slug' => 'demo-gym']);

    Livewire::test(CreateGym::class)
        ->fillForm(['name' => 'Bole Fitness'])
        ->assertSchemaStateSet(['slug' => 'bole-fitness'])
        ->fillForm(['slug' => $slug, 'owner_name' => 'Meron Assefa', 'owner_phone' => '0911223344'])
        ->call('create')
        ->assertHasFormErrors(['slug']);

    expect(Organization::query()->count())->toBe(1);
})->with(['demo-gym', 'admin', 'Bole Fitness']);

it('changes a gym\'s name, time zone and message language, but never its address', function () {
    $gym = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym']);

    Livewire::test(EditGym::class, ['record' => $gym->getRouteKey()])
        ->assertSchemaComponentStateSet('slug', 'demo-gym')
        ->assertFormFieldIsDisabled('slug')
        ->fillForm(['name' => 'Demo Gym Bole', 'timezone' => 'Africa/Nairobi', 'message_language' => 'am', 'slug' => 'renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $gym->refresh();

    expect($gym->name)->toBe('Demo Gym Bole')
        ->and($gym->timezone)->toBe('Africa/Nairobi')
        ->and($gym->messageLanguage())->toBe(Language::Amharic)
        ->and($gym->slug)->toBe('demo-gym');
});

it('resends the owner\'s invite while they have no password, and not after', function () {
    $gym = Organization::factory()->create(['name' => 'Demo Gym']);
    $owner = Staff::factory()->for($gym)->owner()->create();
    $owner->user->update(['password' => null]);

    Livewire::test(ViewGym::class, ['record' => $gym->getRouteKey()])
        ->callAction('resendInvite')
        ->assertNotified('Invite sent by SMS.');

    Notification::assertSentTo($owner->user, PasswordLinkNotification::class);

    $owner->user->update(['password' => 'secret-password']);

    Livewire::test(ViewGym::class, ['record' => $gym->getRouteKey()])
        ->assertActionDisabled('resendInvite');
});

it('opens the new gym form from the gym list', function () {
    $this->get(GymResource::getUrl('create'))->assertOk()->assertSee('First branch and owner');
});
