<?php

declare(strict_types=1);

use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Activity\ActivityRecordResource;
use App\Filament\Staff\Resources\Activity\Pages\ListActivityRecords;
use App\Filament\Staff\Resources\Payments\Pages\ViewPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->gym = actingAsOrganization(Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']));
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole']);
    $this->desk = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $this->desk->user->update(['name' => 'Hana Desk']);
    $plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);

    $atMain = Membership::sell(Member::factory()->for($this->main, 'homeBranch')->create(), $plan, CarbonImmutable::parse('2026-11-28'), $this->desk);
    $this->mainPayment = Payment::receive($atMain, Money::of('2,500'), PaymentMethod::Cash, null, $this->main, CarbonImmutable::parse('2026-11-28'), $this->desk);
    $this->mainPayment->void($this->desk, 'Wrong member.');

    $atBole = Membership::sell(Member::factory()->for($this->bole, 'homeBranch')->create(), $plan, CarbonImmutable::parse('2026-11-28'), $this->desk);
    $this->bolePayment = Payment::receive($atBole, Money::of('2,500'), PaymentMethod::Cash, null, $this->bole, CarbonImmutable::parse('2026-11-28'), $this->desk);
});

it('shows owners who did what with the money, in words, and filters it', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $voided = ActivityRecord::query()->where('action', 'payment_voided')->sole();

    Livewire::test(ListActivityRecords::class)
        ->assertSee(['Hana Desk', 'Payment voided', 'voided: Wrong member.', 'Monthly from 28 Nov 2026 at 2,500.00 ETB'])
        ->filterTable('flagged', true)
        ->assertCanSeeTableRecords([$voided])
        ->assertCountTableRecords(1)
        ->resetTableFilters()
        ->filterTable('branch', $this->bole->id)
        ->assertCountTableRecords(2);
});

it('shows a manager of one branch only that branch and the gym-wide records', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager, $this->bole);

    Livewire::test(ListActivityRecords::class)
        ->assertDontSee('Wrong member.')
        ->assertCountTableRecords(ActivityRecord::query()->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $this->bole->id))->count());
});

it('keeps the log from receptionists, accountants and trainers', function (StaffRole $role) {
    actingAsStaffInPanel($this->gym, $role);

    $this->get(ActivityRecordResource::getUrl('index', tenant: $this->gym))->assertForbidden();
})->with([
    'receptionist' => [StaffRole::Receptionist],
    'accountant' => [StaffRole::Accountant],
    'trainer' => [StaffRole::Trainer],
]);

it('shows a payment\'s history on its page to owners only', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ViewPayment::class, ['record' => $this->mainPayment->getRouteKey()])
        ->assertSee(['History', 'Hana Desk', 'voided: Wrong member.']);

    actingAsStaffInPanel($this->gym, StaffRole::Accountant);

    Livewire::test(ViewPayment::class, ['record' => $this->mainPayment->getRouteKey()])
        ->assertDontSee('Who did what with its money');
});
