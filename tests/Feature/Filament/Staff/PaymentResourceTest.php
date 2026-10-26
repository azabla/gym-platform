<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Payments\Pages\ListPayments;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create();
    $this->branch = Branch::factory()->for($this->gym)->create();
    $this->hana = Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $abebe = Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Abebe Bekele']);
    $hanas = Membership::factory()->for($this->hana)->create();
    $abebes = Membership::factory()->for($abebe)->create();

    $this->cash = Payment::factory()->for($hanas)->create(['amount' => Money::of('1,000'), 'method' => PaymentMethod::Cash, 'paid_on' => '2026-10-20']);
    $this->telebirr = Payment::factory()->for($abebes)->create(['amount' => Money::of('2,000'), 'method' => PaymentMethod::Telebirr, 'reference' => 'TB123', 'paid_on' => '2026-10-22']);
    $this->voided = Payment::factory()->for($abebes)->voided()->create(['amount' => Money::of('700'), 'method' => PaymentMethod::Cash, 'paid_on' => '2026-10-22']);
});

it('lists payments with what they add up to, leaving voided ones out of the totals', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Accountant);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$this->cash, $this->telebirr, $this->voided])
        ->assertSee('Received 3,000.00 ETB, refunded 0.00 ETB, net 3,000.00 ETB');
});

it('filters payments by day, method and member', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListPayments::class)
        ->filterTable('paid_on', ['from' => '2026-10-21', 'to' => '2026-10-22'])
        ->assertCanSeeTableRecords([$this->telebirr, $this->voided])
        ->assertCanNotSeeTableRecords([$this->cash])
        ->assertSee('net 2,000.00 ETB');

    Livewire::test(ListPayments::class)
        ->filterTable('method', PaymentMethod::Cash->value)
        ->assertCanSeeTableRecords([$this->cash, $this->voided])
        ->assertCanNotSeeTableRecords([$this->telebirr]);

    Livewire::test(ListPayments::class)
        ->filterTable('member', $this->hana->id)
        ->assertCanSeeTableRecords([$this->cash])
        ->assertCanNotSeeTableRecords([$this->telebirr, $this->voided]);
});

it('keeps payments from trainers', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    expect(PaymentResource::canViewAny())->toBeFalse();

    $this->get(PaymentResource::getUrl('index', ['tenant' => $this->gym]))->assertForbidden();
});
