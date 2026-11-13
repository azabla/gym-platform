<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Resources\Branches\BranchResource;
use App\Filament\Staff\Resources\Branches\Pages\CreateBranch;
use App\Filament\Staff\Resources\Branches\Pages\EditBranch;
use App\Filament\Staff\Resources\Branches\Pages\ListBranches;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create();
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch', 'code' => 'BOLE']);
});

it('lets an owner add a branch with its opening hours', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreateBranch::class)
        ->fillForm([
            'name' => 'Piassa Branch',
            'code' => 'piassa',
            'address' => 'Piassa, Addis Ababa',
            'hours' => [
                ['weekday' => 1, 'opens_at' => '06:00', 'closes_at' => '21:00'],
                ['weekday' => 6, 'opens_at' => '08:00', 'closes_at' => '14:00'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $piassa = Branch::query()->where('code', 'PIASSA')->sole();

    expect($piassa->openingHours->map(fn ($day) => [$day->weekday, $day->opens_at, $day->closes_at])->all())
        ->toBe([[1, '06:00:00', '21:00:00'], [6, '08:00:00', '14:00:00']]);
});

it('refuses a code another branch has, and closing before opening', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreateBranch::class)
        ->fillForm([
            'name' => 'Bole 2',
            'code' => 'bole',
            'hours' => [['weekday' => 1, 'opens_at' => '21:00', 'closes_at' => '06:00']],
        ])
        ->call('create')
        ->assertHasFormErrors(['code', 'hours.0.closes_at']);
});

it('lets a manager change the hours of their branch, but not see another', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->bole);
    $this->bole->replaceOpeningHours([['weekday' => 1, 'opens_at' => '06:00:00', 'closes_at' => '21:00:00']]);

    Livewire::test(ListBranches::class)
        ->assertCanSeeTableRecords([$this->bole])
        ->assertCanNotSeeTableRecords([$this->main]);

    Livewire::test(EditBranch::class, ['record' => $this->bole->getRouteKey()])
        ->fillForm(['hours' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->bole->refresh()->openingHours)->toBeEmpty();

    $this->get(BranchResource::getUrl('edit', ['record' => $this->main, 'tenant' => $this->gym]))->assertNotFound();
    $this->get(BranchResource::getUrl('create', ['tenant' => $this->gym]))->assertForbidden();
});

it('lets receptionists see their branches but change nothing', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ListBranches::class)->assertCanSeeTableRecords([$this->main]);

    $this->get(BranchResource::getUrl('edit', ['record' => $this->main, 'tenant' => $this->gym]))->assertForbidden();
});
