<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create();
    $branch = Branch::factory()->for($this->gym)->create();
    $this->plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->hana = Member::factory()->for($branch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $abebe = Member::factory()->for($branch, 'homeBranch')->create(['full_name' => 'Abebe Bekele']);

    $this->active = Membership::factory()->for($this->hana)->for($this->plan)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-11-09']);
    $this->expired = Membership::factory()->for($this->hana)->for($this->plan)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
    $this->upcoming = Membership::factory()->for($this->hana)->for($this->plan)->create(['starts_on' => '2026-11-10', 'ends_on' => '2026-12-09']);
    $this->frozen = Membership::factory()->for($abebe)->for($this->plan)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    MembershipFreeze::factory()->for($this->frozen)->create(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-26']);
    $this->cancelled = Membership::factory()->for($abebe)->for($this->plan)->cancelled()->create(['starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);
});

it('narrows the memberships to a status on the gym\'s today', function (string $status, string $expected) {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);
    $memberships = collect(['active', 'expired', 'upcoming', 'frozen', 'cancelled'])->mapWithKeys(fn (string $name) => [$name => $this->{$name}]);

    Livewire::test(ListMemberships::class)
        ->filterTable('status', $status)
        ->assertCanSeeTableRecords([$memberships[$expected]])
        ->assertCanNotSeeTableRecords($memberships->except($expected)->values());
})->with([
    ['active', 'active'],
    ['frozen', 'frozen'],
    ['expired', 'expired'],
    ['upcoming', 'upcoming'],
    ['cancelled', 'cancelled'],
]);

it('narrows the memberships to one member', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListMemberships::class)
        ->filterTable('member', $this->hana->id)
        ->assertCanSeeTableRecords([$this->active, $this->expired, $this->upcoming])
        ->assertCanNotSeeTableRecords([$this->frozen, $this->cancelled]);
});

it('shows a membership with what is paid and owed', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    Payment::factory()->for($this->active)->create(['amount' => Money::of('1,000')]);

    Livewire::test(ViewMembership::class, ['record' => $this->active->getRouteKey()])
        ->assertSee('Hana Girma')
        ->assertSee('Active')
        ->assertSee('1,000.00 ETB')
        ->assertSee('1,500.00 ETB');
});
