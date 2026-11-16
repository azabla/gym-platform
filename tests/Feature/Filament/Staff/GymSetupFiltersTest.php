<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Plans\Pages\CreatePlan;
use App\Filament\Staff\Resources\Plans\Pages\ListPlans;
use App\Filament\Staff\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Staff\Resources\Staff\Pages\ListStaff;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->boleMonthly = Plan::factory()->for($this->gym)->create(['name' => 'Bole Monthly', 'price' => Money::of('2,000')]);
    $this->boleMonthly->makeAvailableOnlyAt($this->bole);
    $this->visits = Plan::factory()->for($this->gym)->create(['name' => '10 Visits', 'visit_limit' => 10, 'duration_count' => 60, 'duration_unit' => DurationUnit::Day]);
});

it('filters plans by where they are sold, visit packs and how they are counted', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListPlans::class)->filterTable('branch', $this->main->id)
        ->assertCanSeeTableRecords([$this->monthly, $this->visits])->assertCanNotSeeTableRecords([$this->boleMonthly]);
    Livewire::test(ListPlans::class)->filterTable('visit_pack', true)
        ->assertCanSeeTableRecords([$this->visits])->assertCanNotSeeTableRecords([$this->monthly]);
    Livewire::test(ListPlans::class)->filterTable('duration_unit', 'day')
        ->assertCanSeeTableRecords([$this->visits])->assertCanNotSeeTableRecords([$this->monthly, $this->boleMonthly]);
});

it('previews the dates and the price a day of a plan being written', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(CreatePlan::class)
        ->fillForm(['price' => '3,000', 'duration_count' => 30, 'duration_unit' => 'day'])
        ->assertSee('Runs from 20 Nov 2026 to 19 Dec 2026, 30 days, about 100.00 ETB a day.');
});

it('filters promo codes by where they stand and by plan', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $active = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR']);
    $ended = PromoCode::factory()->for($this->gym)->create(['code' => 'OCTOBER', 'ends_on' => '2026-10-31']);
    $usedUp = PromoCode::factory()->for($this->gym)->create(['code' => 'FIRST1', 'max_uses' => 1]);
    Membership::factory()->for(Member::factory()->for($this->main, 'homeBranch'))->for($this->monthly)->create(['promo_code_id' => $usedUp->id]);
    $boleOnly = PromoCode::factory()->for($this->gym)->create(['code' => 'BOLE10']);
    $boleOnly->makeAvailableOnlyFor($this->boleMonthly);

    Livewire::test(ListPromoCodes::class)->filterTable('status', 'active')
        ->assertCanSeeTableRecords([$active, $boleOnly])->assertCanNotSeeTableRecords([$ended, $usedUp]);
    Livewire::test(ListPromoCodes::class)->filterTable('status', 'used_up')
        ->assertCanSeeTableRecords([$usedUp])->assertCanNotSeeTableRecords([$active]);
    Livewire::test(ListPromoCodes::class)->filterTable('status', 'ended')
        ->assertCanSeeTableRecords([$ended])->assertCanNotSeeTableRecords([$active]);
    Livewire::test(ListPromoCodes::class)->filterTable('plan', $this->monthly->id)
        ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$boleOnly]);
});

it('filters staff by role, branch and invites still pending', function () {
    $owner = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $receptionist = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $receptionist->assignToBranches($this->bole);
    $invited = Staff::factory()->for($this->gym)->create([
        'role' => StaffRole::Trainer,
        'user_id' => User::factory()->create(['password' => null])->id,
    ]);

    Livewire::test(ListStaff::class)->filterTable('role', StaffRole::Receptionist->value)
        ->assertCanSeeTableRecords([$receptionist])->assertCanNotSeeTableRecords([$owner, $invited]);
    Livewire::test(ListStaff::class)->filterTable('branch', $this->main->id)
        ->assertCanSeeTableRecords([$owner, $invited])->assertCanNotSeeTableRecords([$receptionist]);
    Livewire::test(ListStaff::class)->filterTable('invite_pending', true)
        ->assertCanSeeTableRecords([$invited])->assertCanNotSeeTableRecords([$owner, $receptionist]);
});
