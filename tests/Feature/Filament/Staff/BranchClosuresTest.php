<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\NavigationGroup;
use App\Filament\Staff\Resources\BranchClosures\BranchClosureResource;
use App\Filament\Staff\Resources\BranchClosures\Pages\ListBranchClosures;
use App\Filament\Staff\Resources\Branches\Pages\ListBranches;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    // Saturday 24 October 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));
    fakePushes();

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    Member::factory()->count(2)->for($this->main, 'homeBranch')->create();
});

it('lets an owner close a branch for some days', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListBranchClosures::class)
        ->callAction('addClosure', ['branch_ids' => [$this->main->id], 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-27', 'reason' => 'Repairs'])
        ->assertHasNoActionErrors()
        ->assertNotified('Closure added.');

    $closure = BranchClosure::query()->sole();

    expect($closure->branch_id)->toBe($this->main->id)
        ->and($closure->isClosedAllDay())->toBeTrue()
        ->and(BranchClosureResource::getNavigationGroup())->toBe(NavigationGroup::GymSetup);

    Livewire::test(ListBranchClosures::class)
        ->assertCanSeeTableRecords([$closure])
        ->assertSee('Mon 26 Oct – Tue 27 Oct')
        ->assertSee('Closed');

    Livewire::test(ListBranches::class)->assertSee('Mon 26 Oct – Tue 27 Oct: Repairs');
});

it('opens other hours on a closure\'s days, and refuses one over another', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(ListBranchClosures::class)
        ->callAction('addClosure', ['branch_ids' => [$this->main->id, $this->bole->id], 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'reason' => 'Timket', 'special_hours' => true, 'opens_at' => '08:00', 'closes_at' => '12:00'])
        ->assertHasNoActionErrors()
        ->assertNotified('2 closures added.');

    Livewire::test(ListBranchClosures::class)
        ->assertSee('08:00–12:00')
        ->callAction(TestAction::make('addClosure'), ['branch_ids' => [$this->bole->id], 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'reason' => 'Again'])
        ->assertNotified('Bole Branch already has a closure on some of these days.');

    expect(BranchClosure::query()->count())->toBe(2);
});

it('takes a closure back, and keeps it from staff who may not manage the branch', function () {
    $closure = BranchClosure::factory()->for($this->main)->create();

    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    Livewire::test(ListBranchClosures::class)
        ->assertCanSeeTableRecords([$closure])
        ->assertActionHidden('addClosure')
        ->assertTableActionHidden('remove', $closure);

    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    Livewire::test(ListBranchClosures::class)
        ->callTableAction('remove', $closure)
        ->assertNotified('Closure taken back.');

    expect(BranchClosure::query()->count())->toBe(0);
});

it('lists ended closures only when asked, and only at the staff member\'s branches', function () {
    $coming = BranchClosure::factory()->for($this->main)->create(['reason' => 'Coming']);
    $ended = BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'reason' => 'Ended']);
    $elsewhere = BranchClosure::factory()->for($this->bole)->create(['reason' => 'Elsewhere']);
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->main);

    Livewire::test(ListBranchClosures::class)
        ->assertCanSeeTableRecords([$coming])
        ->assertCanNotSeeTableRecords([$ended, $elsewhere])
        ->filterTable('when', 'past')
        ->assertCanSeeTableRecords([$ended])
        ->assertCanNotSeeTableRecords([$coming, $elsewhere]);
});
