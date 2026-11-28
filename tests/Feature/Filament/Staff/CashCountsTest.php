<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\CashCount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\PanelUser;
use App\Filament\Staff\Resources\CashCounts\CashCountResource;
use App\Filament\Staff\Resources\CashCounts\Pages\ListCashCounts;
use App\Filament\Staff\Widgets\CashToCount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-28 18:00', 'Africa/Addis_Ababa'));
    $this->gym = actingAsOrganization(Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']));
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main']);
    $this->plan = Plan::factory()->for($this->gym)->create(['price' => Money::of('3,000')]);
});

function takeCash(string $amount, Staff $by, Branch $at): Payment
{
    $membership = Membership::sell(Member::factory()->for($at, 'homeBranch')->create(), test()->plan, CarbonImmutable::parse('2026-11-28'), $by);

    return Payment::receive($membership, Money::of($amount), PaymentMethod::Cash, null, $at, CarbonImmutable::parse('2026-11-28'), $by);
}

it('lets a receptionist close their cash at the branch where they took it, needing a note when it is off', function () {
    $desk = actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    takeCash('1,000', $desk, $this->main);

    Livewire::test(ListCashCounts::class)
        ->mountAction('close')
        ->assertSchemaStateSet(['branch_id' => (string) $this->main->id])
        ->setActionData(['branch_id' => $this->main->id, 'counted' => '950'])
        ->callMountedAction()
        ->assertNotified('The cash counted is -50.00 ETB off. Say why in a note.');

    Livewire::test(ListCashCounts::class)
        ->callAction('close', ['branch_id' => $this->main->id, 'counted' => '950', 'note' => 'Gave change twice.'])
        ->assertNotified('Cash closed.');

    expect(CashCount::query()->sole()->difference()->toDecimal())->toBe('-50.00');
});

it('shows receptionists only their own counts, and owners everyone\'s to check', function () {
    $hana = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $dawit = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    takeCash('1,000', $hana, $this->main);
    takeCash('500', $dawit, $this->main);
    $hanas = CashCount::close($hana, $this->main, Money::of('1,000'), null);
    $dawits = CashCount::close($dawit, $this->main, Money::of('500'), null);

    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListCashCounts::class)
        ->assertCanSeeTableRecords([$hanas, $dawits])
        ->callTableAction('check', $hanas, ['check_note' => 'Banked.'])
        ->assertNotified('Count checked.')
        ->filterTable('checked', false)
        ->assertCanSeeTableRecords([$dawits])
        ->assertCanNotSeeTableRecords([$hanas]);

    $this->actingAs(PanelUser::query()->findOrFail($hana->user_id), 'staff_panel');
    app(CurrentStaff::class)->set($hana);

    Livewire::test(ListCashCounts::class)
        ->assertCanSeeTableRecords([$hanas])
        ->assertCanNotSeeTableRecords([$dawits])
        ->assertTableActionHidden('check', $hanas);
});

it('keeps cash counts from trainers', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    $this->get(CashCountResource::getUrl('index', tenant: $this->gym))->assertForbidden();
});

it('reminds staff of their cash to count, and owners of what is waiting and to check', function () {
    $desk = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    takeCash('1,000', $desk, $this->main);
    takeCash('300', $desk, $this->main);
    $owner = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    takeCash('200', $owner, $this->main);

    Livewire::test(CashToCount::class)
        ->assertSee(['Your cash to count', '200.00 ETB', 'Cash not yet counted', '1,300.00 ETB', 'By 1 staff member']);
});

it('closes, lists and checks counts in the staff API', function () {
    $desk = actingAsStaff($this->gym, StaffRole::Receptionist);
    takeCash('1,000', $desk, $this->main);

    $this->getJson('/api/v1/organizations/demo-gym/cash-counts/expected')
        ->assertOk()
        ->assertJsonPath('data.0.expected.amount_minor', 100000);

    $this->postJson('/api/v1/organizations/demo-gym/cash-counts', ['branch_id' => $this->main->id, 'counted' => '900'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    $id = $this->postJson('/api/v1/organizations/demo-gym/cash-counts', ['branch_id' => $this->main->id, 'counted' => '900', 'note' => 'Short 100.'])
        ->assertCreated()
        ->assertJsonPath('data.difference.amount_minor', -10000)
        ->json('data.id');

    $this->postJson("/api/v1/organizations/demo-gym/cash-counts/{$id}/check")->assertForbidden();

    actingAsStaff($this->gym, StaffRole::Manager);

    $this->getJson('/api/v1/organizations/demo-gym/cash-counts?checked=0')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/organizations/demo-gym/cash-counts/{$id}/check", ['note' => 'OK'])
        ->assertOk()
        ->assertJsonPath('data.checked', true);
});
