<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\PasswordLinkNotification;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\Resources\Staff\Pages\CreateStaff;
use App\Filament\Staff\Resources\Staff\Pages\EditStaff;
use App\Filament\Staff\Resources\Staff\Pages\ListStaff;
use App\Filament\Staff\Resources\Staff\StaffResource;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
});

it('lets an owner add a staff member by phone, who gets an invite by SMS', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => 'Selam Tadesse', 'phone' => '0944 55 66 77', 'role' => 'receptionist', 'branch_ids' => [$this->boleBranch->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $selam = User::query()->where('phone', '+251944556677')->sole();
    $staff = Staff::query()->whereBelongsTo($selam)->sole();

    expect($selam->name)->toBe('Selam Tadesse')
        ->and($staff->role)->toBe(StaffRole::Receptionist)
        ->and($staff->limitedToBranchIds())->toBe([$this->boleBranch->id]);
    Notification::assertSentTo($selam, PasswordLinkNotification::class);
});

it('keeps a manager to the roles below them and their own branches', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->mainBranch);

    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => 'Selam Tadesse', 'phone' => '0944 55 66 77', 'role' => 'manager', 'branch_ids' => [$this->mainBranch->id]])
        ->call('create')
        ->assertHasFormErrors(['role']);

    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => 'Selam Tadesse', 'phone' => '0944 55 66 77', 'role' => 'trainer', 'branch_ids' => []])
        ->call('create')
        ->assertHasFormErrors(['branch_ids']);

    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => 'Selam Tadesse', 'phone' => '0944 55 66 77', 'role' => 'trainer', 'branch_ids' => [$this->mainBranch->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('phone', '+251944556677')->count())->toBe(1);
});

it('refuses someone who already works at the gym', function () {
    $staff = Staff::factory()->for($this->gym)->create();
    $staff->user->update(['phone' => '0944 55 66 77']);
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => 'Selam Tadesse', 'phone' => '0944 55 66 77', 'role' => 'trainer'])
        ->call('create')
        ->assertHasFormErrors(['phone']);
});

it('changes a role and branches', function () {
    $receptionist = Staff::factory()->for($this->gym)->create();
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(EditStaff::class, ['record' => $receptionist->getRouteKey()])
        ->assertFormSet(['role' => 'receptionist', 'branch_ids' => []])
        ->fillForm(['role' => 'accountant', 'branch_ids' => [$this->mainBranch->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $receptionist->refresh();
    expect($receptionist->role)->toBe(StaffRole::Accountant)
        ->and($receptionist->limitedToBranchIds())->toBe([$this->mainBranch->id]);
});

it('archives and restores a staff member', function () {
    $trainer = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Trainer]);
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(EditStaff::class, ['record' => $trainer->getRouteKey()])
        ->assertActionHidden('restore')
        ->callAction('archive')
        ->assertNotified('Staff member archived.');

    expect($trainer->fresh()->trashed())->toBeTrue();

    Livewire::test(EditStaff::class, ['record' => $trainer->getRouteKey()])
        ->assertActionHidden('archive')
        ->callAction('restore');

    expect($trainer->fresh()->trashed())->toBeFalse();
});

it('sends the invite again only while it is pending', function () {
    $invited = Staff::factory()->for($this->gym)->create(['user_id' => User::factory()->withPhone('0944 55 66 77')]);
    $signedUp = Staff::factory()->for($this->gym)->create();
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(EditStaff::class, ['record' => $invited->getRouteKey()])
        ->callAction('resendInvite')
        ->assertNotified('Invite sent by SMS.');
    Livewire::test(EditStaff::class, ['record' => $signedUp->getRouteKey()])
        ->assertActionHidden('resendInvite');

    Notification::assertSentTo($invited->user, PasswordLinkNotification::class);
});

it('never lets staff archive themselves, nor a manager touch an owner', function () {
    $owner = Staff::factory()->for($this->gym)->owner()->create();
    $manager = actingAsStaffInPanel($this->gym, StaffRole::Manager);

    $this->get(StaffResource::getUrl('edit', ['record' => $owner, 'tenant' => $this->gym]))->assertForbidden();
    $this->get(StaffResource::getUrl('edit', ['record' => $manager, 'tenant' => $this->gym]))->assertForbidden();
});

it('lists only staff at a limited manager\'s branches, and keeps the list from other roles', function () {
    $atBole = Staff::factory()->for($this->gym)->create();
    $atBole->assignToBranches($this->boleBranch);
    $atMain = Staff::factory()->for($this->gym)->create();
    $atMain->assignToBranches($this->mainBranch);
    $manager = actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->mainBranch);

    Livewire::test(ListStaff::class)
        ->assertCanSeeTableRecords([$atMain, $manager])
        ->assertCanNotSeeTableRecords([$atBole]);

    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $this->get(StaffResource::getUrl('index', ['tenant' => $this->gym]))->assertForbidden();
});
