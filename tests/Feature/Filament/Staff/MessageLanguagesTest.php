<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Language\Language;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Pages\GymSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym']);
});

it('lets the owner choose the language members get texts in', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(GymSettings::class)
        ->assertSee('Demo Gym')
        ->assertSee('/staff/demo-gym')
        ->assertSee('Africa/Addis_Ababa')
        ->callAction('changeMessageLanguage', ['message_language' => 'am'])
        ->assertHasNoActionErrors()
        ->assertNotified('Members now get texts in Amharic (አማርኛ).');

    expect($this->gym->refresh()->messageLanguage())->toBe(Language::Amharic)
        ->and(GymSettings::getNavigationGroup())->toBe(NavigationGroup::GymSetup);
});

it('shows other staff the settings without letting them change them', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(GymSettings::class)
        ->assertSee('English')
        ->assertActionHidden('changeMessageLanguage');
});
