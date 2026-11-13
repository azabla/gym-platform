<?php

declare(strict_types=1);

use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Plans\Pages\CreatePlan;
use App\Filament\Staff\Resources\Plans\Pages\EditPlan;
use App\Filament\Staff\Resources\Plans\Pages\ListPlans;
use App\Filament\Staff\Resources\Plans\PlanResource;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create();
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->monthly = [
        'name' => 'Monthly',
        'price' => '2,500',
        'duration_count' => 1,
        'duration_unit' => 'month',
        'freeze_days_allowed' => 7,
    ];
});

it('lets a manager add a plan sold at every branch, or only at some', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(CreatePlan::class)
        ->fillForm($this->monthly)
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreatePlan::class)
        ->fillForm([...$this->monthly, 'name' => 'Bole Monthly', 'price' => '2,000', 'branch_ids' => [$this->bole->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $monthly = Plan::query()->where('name', 'Monthly')->sole();
    $boleMonthly = Plan::query()->where('name', 'Bole Monthly')->sole();

    expect($monthly->price)->toEqual(Money::of('2,500'))
        ->and($monthly->freeze_days_allowed)->toBe(7)
        ->and($monthly->available_at_all_branches)->toBeTrue()
        ->and($boleMonthly->limitedToBranchIds())->toBe([$this->bole->id]);
});

it('refuses a name another plan has, whatever the capitals, and a duration too long', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    Plan::factory()->for($this->gym)->create(['name' => 'Monthly']);

    Livewire::test(CreatePlan::class)
        ->fillForm([...$this->monthly, 'name' => 'MONTHLY', 'duration_count' => 37])
        ->call('create')
        ->assertHasFormErrors(['name', 'duration_count']);
});

it('keeps a manager limited to a branch to plans sold only there', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->bole);
    $everywhere = Plan::factory()->for($this->gym)->create();
    $boleOnly = Plan::factory()->for($this->gym)->create();
    $boleOnly->makeAvailableOnlyAt($this->bole);

    $this->get(PlanResource::getUrl('edit', ['record' => $everywhere, 'tenant' => $this->gym]))->assertForbidden();

    Livewire::test(EditPlan::class, ['record' => $boleOnly->getRouteKey()])
        ->assertSchemaStateSet(['branch_ids' => [$this->bole->id]])
        ->fillForm(['name' => 'Bole Monthly'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($boleOnly->refresh()->name)->toBe('Bole Monthly');

    Livewire::test(CreatePlan::class)
        ->fillForm([...$this->monthly, 'branch_ids' => []])
        ->call('create')
        ->assertHasFormErrors(['branch_ids']);
});

it('archives a plan, which then leaves the list', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $plan = Plan::factory()->for($this->gym)->create();

    Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('archive');

    expect($plan->refresh()->trashed())->toBeTrue();

    Livewire::test(ListPlans::class)->assertCanNotSeeTableRecords([$plan]);
});

it('shows plans to every staff member, but lets only owners and managers change them', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $plan = Plan::factory()->for($this->gym)->create();

    Livewire::test(ListPlans::class)->assertCanSeeTableRecords([$plan]);

    $this->get(PlanResource::getUrl('create', ['tenant' => $this->gym]))->assertForbidden();
    $this->get(PlanResource::getUrl('edit', ['record' => $plan, 'tenant' => $this->gym]))->assertForbidden();
});
