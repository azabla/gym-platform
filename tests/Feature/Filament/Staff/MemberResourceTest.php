<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->branch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->hana = Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Hana Girma', 'phone' => '0911223344']);
    $this->abebe = Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Abebe Bekele', 'phone' => '0922334455']);

    $otherGym = Organization::factory()->create();
    $this->stranger = Member::factory()->for(Branch::factory()->for($otherGym), 'homeBranch')->create(['full_name' => 'Someone Else']);
});

it('lists the gym\'s members only', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$this->hana, $this->abebe])
        ->assertCanNotSeeTableRecords([$this->stranger]);
});

it('leaves archived members out', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $this->abebe->delete();

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$this->hana])
        ->assertCanNotSeeTableRecords([$this->abebe]);
});

it('finds members by part of the name or the phone number', function (string $search) {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListMembers::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$this->hana])
        ->assertCanNotSeeTableRecords([$this->abebe]);
})->with(['a name' => 'hana', 'a phone number' => '0911 22']);

it('shows a member with what they owe', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $plan = Plan::factory()->for($this->gym)->create(['price' => Money::of('2,500')]);
    $membership = Membership::factory()->for($this->hana)->for($plan)->create();
    Payment::factory()->for($membership)->create(['amount' => Money::of('1,000'), 'branch_id' => $this->branch->id]);

    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->assertSee('Hana Girma')
        ->assertSee('Main Branch')
        ->assertSee('1,500.00 ETB');
});

it('links a member to their memberships, payments and check-ins, as the staff member may see them', function (StaffRole $role, bool $seesPayments) {
    actingAsStaffInPanel($this->gym, $role);

    $page = Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])
        ->assertActionVisible('memberships')
        ->assertActionVisible('checkIns');

    $seesPayments ? $page->assertActionVisible('payments') : $page->assertActionHidden('payments');
})->with([
    'a receptionist' => [StaffRole::Receptionist, true],
    'a trainer' => [StaffRole::Trainer, false],
]);

it('hides another gym\'s member', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    $this->get(MemberResource::getUrl('view', ['record' => $this->stranger, 'tenant' => $this->gym]))
        ->assertNotFound();
});
