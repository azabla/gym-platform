<?php

declare(strict_types=1);

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00:00', 'Africa/Addis_Ababa'));
    fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $hana = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $this->membership = Membership::factory()->for($hana)->for($monthly)->create(['price' => Money::of('2,500'), 'list_price' => Money::of('2,500')]);
    ChapaAccount::factory()->for($this->gym)->create(['secret_key' => 'CHASECK_TEST-demogymkey']);
    $this->paymentLink = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->main->id]);

    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    $this->accountant = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Accountant]);
    $this->receptionist = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $this->boleManager = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Manager]);
    $this->boleManager->assignToBranches($this->bole);
});

function chapaCallsBack(PaymentLink $paymentLink): void
{
    test()->getJson(route('api.v1.chapa.callback', ['trx_ref' => $paymentLink->tx_ref, 'status' => 'success']))->assertOk();
}

it('tells owners, managers and accountants at the branch that a member paid online', function () {
    fakeChapaPaid($this->paymentLink);

    chapaCallsBack($this->paymentLink);

    $payment = Payment::query()->acrossOrganizations()->sole();
    $message = $this->owner->notifications()->sole();

    expect($message->type)->toBe('staff_online_payment')
        ->and($message->data['details'])->toMatchArray(['member' => 'Hana Girma', 'amount' => '2,500.00 ETB', 'plan' => 'Monthly', 'receipt' => $payment->receipt_number])
        ->and($message->data['data'])->toBe(['payment_id' => (string) $payment->id, 'membership_id' => (string) $this->membership->id])
        ->and($this->accountant->notifications()->count())->toBe(1)
        ->and($this->receptionist->notifications()->count())->toBe(0)
        ->and($this->boleManager->notifications()->count())->toBe(0);
});

it('tells them when Chapa took money that must be refunded', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('2,500')]);
    fakeChapaPaid($this->paymentLink);

    chapaCallsBack($this->paymentLink);

    expect($this->owner->notifications()->sole()->type)->toBe('staff_refund_needed')
        ->and($this->accountant->notifications()->sole()->data['details'])->toEqual(['member' => 'Hana Girma', 'amount' => '2,500.00 ETB']);
});

it('tells staff nothing about money taken at the desk', function () {
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.memberships.payments.store', ['organization' => 'demo-gym', 'membership' => $this->membership]), [
        'amount' => '1,000',
        'method' => 'cash',
        'branch_id' => $this->main->id,
    ])->assertCreated();

    expect($this->owner->notifications()->count())->toBe(0)
        ->and($receptionist->notifications()->count())->toBe(0);
});
