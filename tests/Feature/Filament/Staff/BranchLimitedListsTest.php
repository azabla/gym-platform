<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\CheckIns\Pages\ListCheckIns;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use App\Filament\Staff\Resources\Payments\Pages\ListPayments;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create();
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);

    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $this->abebe = Member::factory()->for($this->bole, 'homeBranch')->create(['full_name' => 'Abebe Bekele']);
    $this->hanas = Membership::factory()->for($this->hana)->create(['price' => Money::of('5,000'), 'list_price' => Money::of('5,000')]);
    $this->abebes = Membership::factory()->for($this->abebe)->create();

    // Hana, of Main, pays and comes in at both branches.
    $this->atMain = Payment::factory()->for($this->hanas)->create(['amount' => Money::of('1,000'), 'branch_id' => $this->main->id]);
    $this->atBole = Payment::factory()->for($this->hanas)->create(['amount' => Money::of('2,000'), 'branch_id' => $this->bole->id]);
    $this->mainVisit = CheckIn::factory()->for($this->hana)->for($this->main)->create();
    $this->boleVisit = CheckIn::factory()->for($this->hana)->for($this->bole)->create();
});

it('lists to staff limited to a branch its members, and finds a member of another by search', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->bole);

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$this->abebe])
        ->assertCanNotSeeTableRecords([$this->hana])
        ->searchTable('Hana')
        ->assertCanSeeTableRecords([$this->hana]);

    // Hana's page and her membership open at Bole's desk.
    Livewire::test(ViewMember::class, ['record' => $this->hana->getRouteKey()])->assertOk()->assertSee('Hana Girma');
    Livewire::test(ViewMembership::class, ['record' => $this->hanas->getRouteKey()])->assertOk();
});

it('lists the memberships of their members, or of the member chosen', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->bole);

    Livewire::test(ListMemberships::class)
        ->assertCanSeeTableRecords([$this->abebes])
        ->assertCanNotSeeTableRecords([$this->hanas])
        ->filterTable('member', $this->hana->id)
        ->assertCanSeeTableRecords([$this->hanas]);
});

it('lists only the payments and check-ins made at their branches, and hides a payment of another', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Accountant, $this->bole);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$this->atBole])
        ->assertCanNotSeeTableRecords([$this->atMain])
        ->assertSee('net 2,000.00 ETB');

    Livewire::test(ListCheckIns::class)
        ->assertCanSeeTableRecords([$this->boleVisit])
        ->assertCanNotSeeTableRecords([$this->mainVisit]);

    $this->get(PaymentResource::getUrl('view', ['record' => $this->atMain, 'tenant' => $this->gym]))->assertNotFound();
    $this->get(PaymentResource::getUrl('view', ['record' => $this->atBole, 'tenant' => $this->gym]))->assertOk();
});

it('shows staff at all branches the whole gym', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListMembers::class)->assertCanSeeTableRecords([$this->hana, $this->abebe]);
    Livewire::test(ListMemberships::class)->assertCanSeeTableRecords([$this->hanas, $this->abebes]);
    Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$this->atMain, $this->atBole]);
    Livewire::test(ListCheckIns::class)->assertCanSeeTableRecords([$this->mainVisit, $this->boleVisit]);
});
