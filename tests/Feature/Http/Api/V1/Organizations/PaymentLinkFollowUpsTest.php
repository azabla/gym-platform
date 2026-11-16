<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Notifications\PaymentLinkSent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create(['phone' => '0933 45 67 89']);
    $plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->membership = Membership::factory()->for($this->hana)->for($plan)->create();

    $this->open = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->main->id, 'amount' => Money::of('1,500')]);
    $this->refundNeeded = PaymentLink::factory()->for($this->membership)->create([
        'branch_id' => $this->main->id,
        'amount' => Money::of('2,500'),
        'status' => PaymentLinkStatus::RefundNeeded,
        'settled_at' => now(),
    ]);
});

function linkRoute(string $name, PaymentLink $paymentLink): string
{
    return route("api.v1.organizations.payment-links.{$name}", ['organization' => 'demo-gym', 'payment_link' => $paymentLink]);
}

it('lets an accountant mark a refund made, with a note', function () {
    $accountant = actingAsStaff($this->gym, StaffRole::Accountant);

    $this->postJson(linkRoute('refunded', $this->refundNeeded), ['note' => 'Chapa refund RF-123'])
        ->assertOk()
        ->assertJsonPath('data.status', 'refunded')
        ->assertJsonPath('data.refund.refunded_by.id', $accountant->id)
        ->assertJsonPath('data.refund.note', 'Chapa refund RF-123');

    $this->postJson(linkRoute('refunded', $this->refundNeeded))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_link' => 'Only a link needing a refund can be marked refunded.']);
});

it('keeps marking refunds from receptionists, and other branches\' links from limited staff', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    $this->postJson(linkRoute('refunded', $this->refundNeeded))->assertForbidden();

    actingAsStaff($this->gym, StaffRole::Accountant, $this->bole);

    $this->postJson(linkRoute('refunded', $this->refundNeeded))->assertNotFound();
});

it('refuses to mark an open link refunded', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(linkRoute('refunded', $this->open))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_link']);
});

it('texts an open link to the member, at most once every 10 minutes', function () {
    Notification::fake();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    $this->postJson(linkRoute('sms', $this->open))
        ->assertAccepted()
        ->assertJsonPath('data.sms_sent_at', now()->toIso8601String());

    Notification::assertSentTo($this->hana, PaymentLinkSent::class, function (PaymentLinkSent $notification): bool {
        return $notification->toSms($this->hana) === "Demo Gym: pay 1,500.00 ETB for Monthly here: {$this->open->checkout_url}";
    });

    $this->travel(9)->minutes();
    $this->postJson(linkRoute('sms', $this->open))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_link']);

    $this->travel(2)->minutes();
    $this->postJson(linkRoute('sms', $this->open))->assertAccepted();

    Notification::assertSentToTimes($this->hana, PaymentLinkSent::class, 2);
});

it('texts only open links, at the staff member\'s branches', function () {
    Notification::fake();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    $this->postJson(linkRoute('sms', $this->refundNeeded))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_link' => 'Only an open link can be texted to the member.']);

    actingAsStaff($this->gym, StaffRole::Receptionist, $this->bole);

    $this->postJson(linkRoute('sms', $this->open))->assertNotFound();
    Notification::assertNothingSent();
});

it('shows a membership\'s open link, though not one at another branch', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->main);

    $this->getJson(route('api.v1.organizations.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->membership]))
        ->assertOk()
        ->assertJsonPath('data.open_payment_link.id', $this->open->id)
        ->assertJsonPath('data.open_payment_link.amount.formatted', '1,500.00 ETB')
        ->assertJsonPath('data.open_payment_link.checkout_url', $this->open->checkout_url);

    actingAsStaff($this->gym, StaffRole::Receptionist, $this->bole);

    $this->getJson(route('api.v1.organizations.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->membership]))
        ->assertOk()
        ->assertJsonPath('data.open_payment_link', null);
});

it('counts the refunds still to make on the dashboard, for those who see money', function () {
    actingAsStaff($this->gym, StaffRole::Accountant);

    $this->getJson(route('api.v1.organizations.dashboard', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.money.refunds_needed.count', 1)
        ->assertJsonPath('data.money.refunds_needed.amount.formatted', '2,500.00 ETB');

    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(route('api.v1.organizations.dashboard', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonMissingPath('data.money');
});
