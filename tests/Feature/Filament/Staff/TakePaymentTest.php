<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Actions\RefundAction;
use App\Filament\Staff\Actions\TakePaymentAction;
use App\Filament\Staff\Actions\VoidPaymentAction;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use App\Filament\Staff\Resources\Payments\Pages\ViewPayment;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create();
    $this->mainBranch = Branch::factory()->for($this->gym)->create();
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    $plan = Plan::factory()->for($this->gym)->create(['price' => Money::of('2,500')]);
    $this->membership = Membership::factory()->for($member)->for($plan)->create(['price' => Money::of('2,500'), 'list_price' => Money::of('2,500')]);
});

it('lets a receptionist take cash at their branch, suggesting what is due', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->mainBranch);

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->mountAction(TakePaymentAction::class)
        ->assertSchemaStateSet(['amount' => '2500.00', 'branch_id' => $this->mainBranch->id, 'paid_on' => '2026-10-24'])
        ->fillForm(['amount' => '1,000', 'method' => PaymentMethod::Cash->value])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('1,000.00 ETB received, receipt 1.');

    expect(Payment::balanceOf($this->membership)->balanceDue)->toEqual(Money::of('1,500'));
});

it('refuses more than is due', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->callAction(TakePaymentAction::class, ['amount' => '3,000', 'method' => PaymentMethod::Cash->value, 'branch_id' => $this->mainBranch->id])
        ->assertNotified('Only 2,500.00 ETB is still due on this membership.');

    expect(Payment::query()->count())->toBe(0);
});

it('asks for a transaction number, and refuses one already recorded', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('500'), 'method' => PaymentMethod::Telebirr, 'reference' => 'TB12345']);

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->callAction(TakePaymentAction::class, ['amount' => '500', 'method' => PaymentMethod::Telebirr->value, 'branch_id' => $this->mainBranch->id])
        ->assertHasFormErrors(['reference' => 'required']);

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->callAction(TakePaymentAction::class, ['amount' => '500', 'method' => PaymentMethod::Telebirr->value, 'reference' => 'tb 123 45', 'branch_id' => $this->mainBranch->id])
        ->assertHasFormErrors(['reference']);

    expect(Payment::query()->count())->toBe(1);
});

it('keeps past dates to owners, managers and accountants', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->callAction(TakePaymentAction::class, ['amount' => '500', 'method' => PaymentMethod::Cash->value, 'branch_id' => $this->mainBranch->id, 'paid_on' => '2026-10-20'])
        ->assertHasFormErrors(['paid_on']);
});

it('lets an accountant refund a cancelled membership and void a payment', function () {
    $accountant = actingAsStaffInPanel($this->gym, StaffRole::Accountant);
    $payment = Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000'), 'method' => PaymentMethod::Cash]);
    $this->membership->cancel($accountant, 'Moved away.');

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->assertActionHidden(TakePaymentAction::class)
        ->callAction(RefundAction::class, ['amount' => '400', 'method' => PaymentMethod::Cash->value, 'branch_id' => $this->mainBranch->id])
        ->assertHasNoFormErrors();

    expect(Payment::query()->where('type', PaymentType::Refund)->sole()->amount)->toEqual(Money::of('400'));

    $refund = Payment::query()->where('type', PaymentType::Refund)->sole();

    Livewire::test(ViewPayment::class, ['record' => $refund->getRouteKey()])
        ->callAction(VoidPaymentAction::class, ['reason' => 'Recorded twice.'])
        ->assertNotified("Receipt {$refund->receipt_number} voided.");

    expect($refund->refresh()->voided_at)->not->toBeNull()
        ->and($payment->refresh()->voided_at)->toBeNull();
});

it('keeps voids from receptionists', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $payment = Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertActionHidden(VoidPaymentAction::class);
});
