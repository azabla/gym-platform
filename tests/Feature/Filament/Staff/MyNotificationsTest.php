<?php

declare(strict_types=1);

use App\Domains\Notifications\Enums\StaffNotificationTopic;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Language\Language;
use App\Filament\Staff\Pages\GymSettings;
use App\Filament\Staff\Pages\MyNotifications;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym']);
});

it('lets each staff member choose their message language, and push and SMS for each topic', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Manager);
    auth()->user()->update(['phone' => '0911 22 33 44']);

    Livewire::test(MyNotifications::class)
        ->assertSchemaStateSet(['message_language' => 'en', 'topics.refunds.push' => true, 'topics.refunds.sms' => false])
        ->assertSee('At Demo Gym')
        ->assertSee('Refunds needed')
        ->assertSee('Members to call')
        ->fillForm(['message_language' => 'am', 'topics.refunds.sms' => true, 'topics.members_to_call.push' => false])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved. Your messages come in Amharic (አማርኛ).');

    $staff->refresh();

    expect(auth()->user()->refresh()->messageLanguage())->toBe(Language::Amharic)
        ->and($staff->wantsTextsAbout(StaffNotificationTopic::Refunds))->toBeTrue()
        ->and($staff->wantsPushesAbout(StaffNotificationTopic::MembersToCall))->toBeFalse()
        ->and($staff->wantsPushesAbout(StaffNotificationTopic::OnlinePayments))->toBeTrue();
});

it('offers only the topics the role hears about', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(MyNotifications::class)
        ->assertSee('Members to call')
        ->assertSee('Freezes in the app')
        ->assertDontSee('Refunds needed')
        ->assertDontSee('Paid online');

    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(MyNotifications::class)
        ->assertSee('Language')
        ->assertDontSee('At Demo Gym');
});

it('puts My notifications in the user menu', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    $this->get(GymSettings::getUrl(tenant: $this->gym))
        ->assertOk()
        ->assertSee('My notifications')
        ->assertSee(MyNotifications::getUrl(tenant: $this->gym), escape: false);
});
