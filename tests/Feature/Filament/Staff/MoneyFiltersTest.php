<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentType;
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
use App\Filament\Staff\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Filament\Staff\Resources\Payments\Pages\ListPayments;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->main = Branch::factory()->for($this->gym)->create();
    $plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->membership = Membership::factory()->for(Member::factory()->for($this->main, 'homeBranch'))->for($plan)->create();
    $this->receptionist = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $this->accountant = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Accountant]);
});

it('filters payments by type, voided and who took them', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $payment = Payment::factory()->for($this->membership)->create(['branch_id' => $this->main->id, 'received_by_staff_id' => $this->receptionist->id]);
    $refund = Payment::factory()->for($this->membership)->create(['branch_id' => $this->main->id, 'type' => PaymentType::Refund, 'received_by_staff_id' => $this->accountant->id]);
    $voided = Payment::factory()->for($this->membership)->voided()->create(['branch_id' => $this->main->id, 'received_by_staff_id' => $this->receptionist->id]);

    $list = fn () => Livewire::test(ListPayments::class);

    $list()->filterTable('type', PaymentType::Refund->value)
        ->assertCanSeeTableRecords([$refund])->assertCanNotSeeTableRecords([$payment, $voided]);
    $list()->filterTable('voided', true)
        ->assertCanSeeTableRecords([$voided])->assertCanNotSeeTableRecords([$payment, $refund]);
    $list()->filterTable('voided', false)
        ->assertCanSeeTableRecords([$payment, $refund])->assertCanNotSeeTableRecords([$voided]);
    $list()->filterTable('received_by', $this->accountant->id)
        ->assertCanSeeTableRecords([$refund])->assertCanNotSeeTableRecords([$payment]);
});

it('filters payment links by when they were made, what they are for, and whether they were texted', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $older = PaymentLink::factory()->for($this->membership)->cancelled()->create(['branch_id' => $this->main->id, 'created_at' => CarbonImmutable::parse('2026-11-01 09:00', 'Africa/Addis_Ababa')]);
    $texted = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->main->id, 'sms_sent_at' => now(), 'created_at' => now()]);
    $forAPlan = PaymentLink::factory()->buying(Plan::query()->firstOrFail(), $this->membership->member)->create(['branch_id' => $this->main->id, 'status' => PaymentLinkStatus::Cancelled, 'cancelled_at' => now(), 'created_at' => now()]);

    $list = fn () => Livewire::test(ListPaymentLinks::class);

    $list()->filterTable('created', ['from' => '2026-11-15'])
        ->assertCanSeeTableRecords([$texted, $forAPlan])->assertCanNotSeeTableRecords([$older]);
    $list()->filterTable('for', 'plan')
        ->assertCanSeeTableRecords([$forAPlan])->assertCanNotSeeTableRecords([$texted, $older]);
    $list()->filterTable('texted', true)
        ->assertCanSeeTableRecords([$texted])->assertCanNotSeeTableRecords([$older, $forAPlan]);
});
