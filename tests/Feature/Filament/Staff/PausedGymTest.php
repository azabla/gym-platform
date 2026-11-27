<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\Auth\Login;
use App\Filament\Staff\PanelUser;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('staff');

    $this->demo = Organization::factory()->create(['name' => 'Demo Gym', 'slug' => 'demo-gym']);
    $this->bole = Organization::factory()->create(['name' => 'Bole Fitness', 'slug' => 'bole-fitness']);
    $this->admin = User::factory()->create();

    $this->receptionist = Staff::factory()->for($this->demo)->create();
    $this->receptionist->user->update(['phone' => '+251911223344', 'password' => 'secret-password']);
});

afterEach(function () {
    app()->setLocale('en');
    Carbon::setLocale('en');
    CarbonImmutable::setLocale('en');
});

it('tells someone whose only gym is paused, instead of signing them in', function () {
    $this->demo->suspend($this->admin, 'Hasn\'t paid.');

    Livewire::test(Login::class)
        ->fillForm(['phone' => '0911223344', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['phone' => 'This gym\'s account is paused for now. Please contact the platform\'s admins.']);

    $this->assertGuest('staff_panel');
});

it('still signs in someone who works at another gym, taking them there', function () {
    Staff::factory()->for($this->bole)->create(['user_id' => $this->receptionist->user_id]);
    $this->demo->suspend($this->admin, 'Hasn\'t paid.');

    Livewire::test(Login::class)
        ->fillForm(['phone' => '0911223344', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('filament.staff.pages.dashboard', ['tenant' => 'bole-fitness']));
});

it('shows a paused gym\'s page in the staff member\'s language, with their other gyms, and opens it again once resumed', function () {
    Staff::factory()->for($this->bole)->create(['user_id' => $this->receptionist->user_id]);
    $this->receptionist->user->update(['message_language' => 'am']);
    $this->actingAs(PanelUser::query()->findOrFail($this->receptionist->user_id), 'staff_panel');
    $this->demo->suspend($this->admin, 'Hasn\'t paid.');

    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertForbidden()
        ->assertSee('የዚህ ጂም አገልግሎት ለጊዜው ተቋርጧል።')
        ->assertSee('Bole Fitness')
        ->assertSee(route('filament.staff.pages.dashboard', ['tenant' => 'bole-fitness']), escape: false);

    $this->demo->resume($this->admin);

    $this->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))->assertOk();
});
