<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Pages\Inbox;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use App\Notifications\RefundNeeded;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->membership = Membership::factory()
        ->for(Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Hana Girma']))
        ->for($plan)
        ->create(['price' => Money::of('2,500'), 'list_price' => Money::of('2,500')]);

    $this->atMain = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->main->id, 'amount' => Money::of('2,500')]);
    $this->atBole = PaymentLink::factory()->for($this->membership)->cancelled()->create(['branch_id' => $this->bole->id]);
    $this->refundNeeded = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->main->id, 'status' => PaymentLinkStatus::RefundNeeded, 'settled_at' => now()]);
});

it('lists the links at the staff member\'s branches, and filters those needing a refund', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ListPaymentLinks::class)
        ->assertCanSeeTableRecords([$this->atMain, $this->refundNeeded])
        ->assertCanNotSeeTableRecords([$this->atBole])
        ->filterTable('status', PaymentLinkStatus::RefundNeeded->value)
        ->assertCanSeeTableRecords([$this->refundNeeded])
        ->assertCanNotSeeTableRecords([$this->atMain]);
});

it('shows a waiting link with its address to copy, and keeps another branch\'s link from opening', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->atMain->getRouteKey()])
        ->assertSee('Hana Girma')
        ->assertSee($this->atMain->checkout_url)
        ->assertActionVisible('check')
        ->assertActionVisible('cancel');

    $this->get(PaymentLinkResource::getUrl('view', ['record' => $this->atBole, 'tenant' => $this->gym]))->assertNotFound();
});

it('keeps payment links from trainers', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    $this->get(PaymentLinkResource::getUrl('index', ['tenant' => $this->gym]))->assertForbidden();
});

it('asks Chapa about a link, and records the payment once the member has paid', function () {
    ChapaAccount::factory()->for($this->gym)->create();
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => Http::sequence()
        ->push(['message' => 'Invalid transaction or Transaction not found', 'status' => 'failed', 'data' => null], 404)
        ->push(chapaPaidAnswer($this->atMain))]);
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->atMain->getRouteKey()])
        ->callAction('check')
        ->assertNotified('Not paid yet.')
        ->callAction('check');

    $paymentLink = $this->atMain->refresh();
    expect($paymentLink->status)->toBe(PaymentLinkStatus::Paid)
        ->and($paymentLink->payment->method)->toBe(PaymentMethod::Chapa)
        ->and($paymentLink->payment->amount)->toEqual(Money::of('2,500'));
});

it('says so, and keeps the link waiting, when Chapa doesn\'t answer or isn\'t connected', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->atMain->getRouteKey()])
        ->callAction('check')
        ->assertNotified('Connect the gym\'s Chapa account first.');

    ChapaAccount::factory()->for($this->gym)->create();
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => Http::response(null, 503)]);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->atMain->getRouteKey()])
        ->callAction('check')
        ->assertNotified('Chapa didn\'t answer. Try again in a moment.');

    expect($this->atMain->refresh()->status)->toBe(PaymentLinkStatus::Pending);
});

it('cancels a waiting link', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->atMain->getRouteKey()])
        ->callAction('cancel')
        ->assertNotified('The link is cancelled.')
        ->assertActionHidden('cancel');

    expect($this->atMain->refresh()->status)->toBe(PaymentLinkStatus::Cancelled)
        ->and($this->atMain->cancelled_by_staff_id)->toBe($staff->id);
});

it('opens the link needing a refund from the inbox', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $staff->notify(RefundNeeded::for($this->refundNeeded));

    Livewire::test(Inbox::class)
        ->callTableAction('open', $staff->notifications()->sole())
        ->assertRedirect(PaymentLinkResource::getUrl('view', ['record' => $this->refundNeeded->id]));
});
