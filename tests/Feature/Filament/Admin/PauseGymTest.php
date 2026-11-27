<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\GymSuspension;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Admin\AdminUser;
use App\Filament\Admin\Resources\Gyms\Pages\ListGyms;
use App\Filament\Admin\Resources\Gyms\Pages\ViewGym;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'Selam Tadesse', 'phone' => '+251911000000']);
    $this->admin->makePlatformAdmin();
    $this->actingAs(AdminUser::query()->findOrFail($this->admin->id), 'admin_panel');
    Filament::setCurrentPanel('admin');

    $this->gym = Organization::factory()->create(['name' => 'Demo Gym']);
});

it('pauses a gym with a reason and resumes it, showing who did it in its history', function () {
    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->assertActionHidden('resume')
        ->callAction('pause', ['reason' => 'Hasn\'t paid for November.'])
        ->assertNotified('Gym paused.');

    expect($this->gym->refresh()->isSuspended())->toBeTrue()
        ->and(GymSuspension::query()->acrossOrganizations()->sole()->suspended_by_user_id)->toBe($this->admin->id);

    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->assertSee('Hasn\'t paid for November.')
        ->assertActionHidden('pause')
        ->callAction('resume', ['note' => 'Paid on 2 December'])
        ->assertNotified('Gym resumed.');

    expect($this->gym->refresh()->isSuspended())->toBeFalse();

    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->assertSee('by Selam Tadesse: Hasn\'t paid for November. · Resumed');
});

it('needs a reason to pause a gym', function () {
    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->callAction('pause', ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($this->gym->refresh()->isSuspended())->toBeFalse();
});

it('shows and filters paused gyms in the list', function () {
    $paused = Organization::factory()->create(['name' => 'Paused Gym']);
    $paused->suspend($this->admin, 'Closed for renovation');

    Livewire::test(ListGyms::class)
        ->assertSee('Paused')
        ->filterTable('status', 'paused')
        ->assertCanSeeTableRecords([$paused])
        ->assertCanNotSeeTableRecords([$this->gym])
        ->filterTable('status', 'active')
        ->assertCanSeeTableRecords([$this->gym])
        ->assertCanNotSeeTableRecords([$paused]);
});
