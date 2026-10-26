<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Pages\Dashboard;
use App\Filament\Staff\Widgets\GymOverview;
use App\Filament\Staff\Widgets\MembersToCall;
use App\Filament\Staff\Widgets\MoneyOverview;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);

    // Hana paid 1,000 today at Main Branch, and checked in twice.
    $hana = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $hanas = Membership::factory()->for($hana)->for($monthly)->create(['starts_on' => '2026-10-24', 'ends_on' => '2026-11-23']);
    Payment::factory()->for($hanas)->create(['amount' => Money::of('1,000'), 'branch_id' => $this->mainBranch->id]);
    CheckIn::factory()->for($hana)->for($this->mainBranch)->count(2)->create();

    // Abebe paid 2,000 of 2,500 at Bole Branch; his membership ends in 3 days, and he was turned away once.
    $abebe = Member::factory()->for($this->boleBranch, 'homeBranch')->create(['full_name' => 'Abebe Bekele']);
    $abebes = Membership::factory()->for($abebe)->for($monthly)->create(['starts_on' => '2026-09-28', 'ends_on' => '2026-10-27']);
    Payment::factory()->for($abebes)->create(['amount' => Money::of('2,000'), 'branch_id' => $this->boleBranch->id]);
    CheckIn::factory()->for($abebe)->for($this->boleBranch)->denied(CheckInDenial::MembershipExpired)->create();
});

it('shows an owner the money taken and owed today', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    expect(MoneyOverview::canView())->toBeTrue();

    Livewire::test(MoneyOverview::class)
        ->assertSee('3,000.00 ETB')
        ->assertSee('By 2 members');
});

it('keeps the money from receptionists', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    expect(MoneyOverview::canView())->toBeFalse();

    Livewire::test(MembersToCall::class)
        ->assertSee('Abebe Bekele')
        ->assertDontSee('Balance due');
});

it('shows check-ins and members for the period chosen', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(GymOverview::class)
        ->assertSeeInOrder(['Check-ins', '2', '1 turned away'])
        ->assertSeeInOrder(['Members with a valid membership today', '2']);

    Livewire::test(GymOverview::class, ['pageFilters' => ['from' => '2026-10-20', 'to' => '2026-10-23']])
        ->assertSeeInOrder(['Check-ins', '0', '0 turned away']);
});

it('covers only the branches a staff member works at, whatever branch is chosen', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->boleBranch);

    Livewire::test(MoneyOverview::class, ['pageFilters' => ['branch_id' => $this->mainBranch->id]])
        ->assertSee('2,000.00 ETB')
        ->assertDontSee('1,000.00 ETB');

    Livewire::test(GymOverview::class)
        ->assertSeeInOrder(['Check-ins', '0', '1 turned away']);
});

it('lists the members to call, with what they owe', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(MembersToCall::class)
        ->assertSee('Abebe Bekele')
        ->assertSee('500.00 ETB')
        ->assertDontSee('Hana Girma');
});

it('opens on today, at all the staff member\'s branches', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(Dashboard::class)
        ->assertSet('filters.from', '2026-10-24')
        ->assertSet('filters.branch_id', null);
});
