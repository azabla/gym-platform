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
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Actions\SendPaymentLinkAction;
use App\Filament\Staff\Pages\Inbox;
use App\Filament\Staff\Resources\Memberships\Pages\ViewMembership;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Filament\Staff\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use App\Filament\Staff\Widgets\MoneyOverview;
use App\Notifications\PaymentLinkSent;
use App\Notifications\RefundNeeded;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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

it('lets an accountant mark a refund made, with a note, but not a receptionist', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->refundNeeded->getRouteKey()])
        ->assertActionHidden('markRefunded');

    $accountant = actingAsStaffInPanel($this->gym, StaffRole::Accountant);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->refundNeeded->getRouteKey()])
        ->callAction('markRefunded', ['note' => 'Chapa refund RF-123'])
        ->assertNotified('Marked refunded.')
        ->assertActionHidden('markRefunded')
        ->assertSee('Chapa refund RF-123');

    $paymentLink = $this->refundNeeded->refresh();
    expect($paymentLink->status)->toBe(PaymentLinkStatus::Refunded)
        ->and($paymentLink->refunded_by_staff_id)->toBe($accountant->id);
});

it('texts a waiting link to the member, from its page or its membership\'s, once every 10 minutes', function () {
    Notification::fake();
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->main);

    Livewire::test(ViewPaymentLink::class, ['record' => $this->atMain->getRouteKey()])
        ->callAction(SendPaymentLinkAction::class)
        ->assertNotified('The link is on its way to the member by SMS.');

    Livewire::test(ViewMembership::class, ['record' => $this->membership->getRouteKey()])
        ->assertSee($this->atMain->checkout_url)
        ->callAction(SendPaymentLinkAction::class)
        ->assertNotified('The link was texted to the member a moment ago. Wait a few minutes before sending it again.');

    Notification::assertSentToTimes($this->membership->member, PaymentLinkSent::class, 1);
});

it('shows the refunds still to make on the dashboard, opening them', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(MoneyOverview::class)
        ->assertSee('Refunds to make')
        ->assertSee('1 payment link');

    $this->refundNeeded->markRefunded(Staff::query()->firstOrFail(), null);

    Livewire::test(MoneyOverview::class)->assertDontSee('Refunds to make');
});
