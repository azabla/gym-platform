<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Members\Pages\CreateMember;
use App\Filament\Staff\Resources\Members\Pages\EditMember;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create();
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
});

it('lets a receptionist register a member, with the phone number stored in E.164', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->mainBranch);

    Livewire::test(CreateMember::class)
        ->assertFormSet(['home_branch_id' => $this->mainBranch->id])
        ->fillForm(['full_name' => 'Selam Tesfaye', 'phone' => '0911 22 33 44'])
        ->call('create')
        ->assertHasNoFormErrors();

    $member = Member::query()->sole();

    expect($member->full_name)->toBe('Selam Tesfaye')
        ->and($member->phone)->toBe('+251911223344')
        ->and($member->home_branch_id)->toBe($this->mainBranch->id)
        ->and($member->organization_id)->toBe($this->gym->id);
});

it('refuses a phone number another member of the gym has, and a branch the staff member doesn\'t work at', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->mainBranch);
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0911223344']);

    Livewire::test(CreateMember::class)
        ->fillForm(['full_name' => 'Selam Tesfaye', 'phone' => '+251 911 223 344', 'home_branch_id' => $this->boleBranch->id])
        ->call('create')
        ->assertHasFormErrors(['phone', 'home_branch_id']);
});

it('asks for both halves of the emergency contact', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(CreateMember::class)
        ->fillForm(['full_name' => 'Selam Tesfaye', 'phone' => '0911223344', 'home_branch_id' => $this->mainBranch->id, 'emergency_contact_name' => 'Almaz'])
        ->call('create')
        ->assertHasFormErrors(['emergency_contact_phone' => 'required_with']);
});

it('lets a receptionist edit a member, keeping their own phone number', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Selam T', 'phone' => '0911223344']);

    Livewire::test(EditMember::class, ['record' => $member->getRouteKey()])
        ->fillForm(['full_name' => 'Selam Tesfaye'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($member->refresh()->full_name)->toBe('Selam Tesfaye');
});

it('archives a member, who then leaves the lists', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create();

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Member::query()->count())->toBe(0)
        ->and(Member::withTrashed()->sole()->trashed())->toBeTrue();
});

it('keeps trainers from registering, editing or archiving members', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create();

    $this->get(MemberResource::getUrl('create', ['tenant' => $this->gym]))->assertForbidden();
    $this->get(MemberResource::getUrl('edit', ['record' => $member, 'tenant' => $this->gym]))->assertForbidden();

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->assertActionHidden(DeleteAction::class);
});
