<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Admin\AdminUser;
use App\Filament\Admin\Auth\Login as AdminLogin;
use App\Filament\Staff\Auth\Login as StaffLogin;
use App\Filament\Staff\PanelUser;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A platform admin, as the platform:admins command makes one.
 */
function platformAdmin(string $phone = '+251911000000'): User
{
    $admin = User::factory()->create(['name' => 'Selam Tadesse', 'phone' => $phone, 'password' => 'secret-password']);
    $admin->makePlatformAdmin();

    return $admin;
}

it('signs a platform admin in with their phone and password', function () {
    Filament::setCurrentPanel('admin');
    $admin = platformAdmin();

    Livewire::test(AdminLogin::class)
        ->fillForm(['phone' => '0911 00 00 00', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('filament.admin.pages.dashboard'));

    $this->assertAuthenticatedAs(AdminUser::query()->findOrFail($admin->id), 'admin_panel');
    $this->get(route('filament.admin.pages.dashboard'))->assertOk()->assertSee('Gym Platform admin');
});

it('keeps gym staff and everyone else out of the admin panel', function () {
    Filament::setCurrentPanel('admin');
    $owner = Staff::factory()->for(Organization::factory())->create();
    $owner->user->update(['phone' => '+251911223344', 'password' => 'secret-password']);

    Livewire::test(AdminLogin::class)
        ->fillForm(['phone' => '0911 22 33 44', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['phone']);

    $this->assertGuest('admin_panel');
    $this->get(route('filament.admin.pages.dashboard'))->assertRedirect(route('filament.admin.auth.login'));

    // Signed in to the staff panel is no way into the admin panel.
    $this->actingAs(PanelUser::query()->findOrFail($owner->user_id), 'staff_panel')
        ->get(route('filament.admin.pages.dashboard'))
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('gives a platform admin who works at no gym no way into the staff panel', function () {
    Filament::setCurrentPanel('staff');
    platformAdmin('+251911000000');

    Livewire::test(StaffLogin::class)
        ->fillForm(['phone' => '0911 00 00 00', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['phone']);

    $this->assertGuest('staff_panel');
});
