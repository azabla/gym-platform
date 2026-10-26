<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\Pages\Auth\Login;
use App\Filament\Staff\PanelUser;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym']);
    $this->otherGym = Organization::factory()->create(['name' => 'Other Gym', 'slug' => 'other-gym']);

    Filament::setCurrentPanel('staff');
});

it('signs staff in with their email and password and takes them to their gym', function () {
    $staff = Staff::factory()->for($this->gym)->create();
    $staff->user->update(['email' => 'hana@example.com', 'password' => 'secret-password']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'Hana@Example.com', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']));

    $this->assertAuthenticatedAs(PanelUser::query()->findOrFail($staff->user_id), 'staff_panel');
});

it('opens the gym the staff member works at, acting as that gym', function () {
    $staff = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Manager]);

    $this->actingAs(PanelUser::query()->findOrFail($staff->user_id), 'staff_panel')
        ->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertOk()
        ->assertSee('Demo Gym');

    expect(app(CurrentOrganization::class)->get()->is($this->gym))->toBeTrue()
        ->and(app(CurrentStaff::class)->get()->is($staff))->toBeTrue();
});

it('lists only the gyms the staff member works at', function () {
    $staff = Staff::factory()->for($this->gym)->create();
    $panelUser = PanelUser::query()->findOrFail($staff->user_id);

    expect($panelUser->getTenants(Filament::getCurrentPanel())->pluck('slug')->all())->toBe(['demo-gym']);
});

it('hides a gym the staff member does not work at', function () {
    $staff = Staff::factory()->for($this->gym)->create();

    $this->actingAs(PanelUser::query()->findOrFail($staff->user_id), 'staff_panel')
        ->get(route('filament.staff.pages.dashboard', ['tenant' => 'other-gym']))
        ->assertNotFound();
});

it('keeps out users who work at no gym', function () {
    $member = User::factory()->create();

    $this->actingAs(PanelUser::query()->findOrFail($member->id), 'staff_panel')
        ->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertForbidden();
});

it('asks guests to sign in', function () {
    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertRedirect(route('filament.staff.auth.login'));
});
