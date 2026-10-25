<?php

declare(strict_types=1);

use App\Domains\Attendance\Credentials\Gq1;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 07:00:00', 'Africa/Addis_Ababa'));
    $this->pushes = fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
        'freeze_days_allowed' => 14,
    ]);
    $this->membership = Membership::factory()->for($this->tigist)->for($this->monthly)->create(['freeze_days_allowed' => 14]);

    $tigistsPhone = User::factory()->withPhone('0922 34 56 78')->create()->createToken('Tigist\'s phone');
    $this->pushToken = PushToken::register($tigistsPhone->accessToken->tokenable_id, $tigistsPhone->accessToken->id, 'tigists-fcm-token', null);
});

it('tells the member about a payment taken at the desk, in the inbox and by push', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.memberships.payments.store', ['organization' => 'demo-gym', 'membership' => $this->membership]), [
        'amount' => '1,000',
        'method' => 'cash',
        'branch_id' => $this->mainBranch->id,
    ])->assertCreated();

    $payment = Payment::query()->acrossOrganizations()->sole();
    $message = $this->tigist->notifications()->sole();
    expect($message->type)->toBe('payment_succeeded')
        ->and($message->data['data'])->toBe(['payment_id' => (string) $payment->id, 'membership_id' => (string) $this->membership->id])
        ->and($this->pushes->sent)->toHaveCount(1);

    [$token, $push] = $this->pushes->sent[0];
    expect($token)->toBe('tigists-fcm-token')
        ->and($push->title)->toBe('Payment received')
        ->and($push->body)->toBe('Your payment of 1,000.00 ETB for Monthly at Demo Gym was recorded.')
        ->and($push->data)->toBe([
            'payment_id' => (string) $payment->id,
            'membership_id' => (string) $this->membership->id,
            'type' => 'payment_succeeded',
            'notification_id' => $message->id,
            'organization_id' => (string) $this->gym->id,
        ]);
});

it('tells the member about a payment confirmed by Chapa, outside any gym\'s request', function () {
    ChapaAccount::factory()->for($this->gym)->create(['secret_key' => 'CHASECK_TEST-demogymkey']);
    $paymentLink = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->mainBranch->id]);
    fakeChapaPaid($paymentLink);

    $this->getJson(route('api.v1.chapa.callback', ['trx_ref' => $paymentLink->tx_ref, 'status' => 'success']))->assertOk();

    expect($this->tigist->notifications()->sole()->type)->toBe('payment_succeeded')
        ->and($this->pushes->sent)->toHaveCount(1);
});

it('says nothing about a payment that was rolled back', function () {
    $staff = actingAsStaff($this->gym, StaffRole::Owner);

    app(CurrentOrganization::class)->run($this->gym, function () use ($staff) {
        try {
            DB::transaction(function () use ($staff) {
                Payment::receive($this->membership, Money::of('500'), PaymentMethod::Cash, null, $this->mainBranch, $this->gym->today(), $staff);

                throw new RuntimeException('The till jammed.');
            });
        } catch (RuntimeException) {
        }
    });

    expect($this->tigist->notifications()->count())->toBe(0)
        ->and($this->pushes->sent)->toBe([]);
});

it('tells the member their membership was frozen, and its new end date', function () {
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(route('api.v1.organizations.memberships.freezes.store', ['organization' => 'demo-gym', 'membership' => $this->membership]), [
        'starts_on' => '2026-10-26',
        'ends_on' => '2026-11-01',
        'reason' => 'Travelling.',
    ])->assertCreated();

    expect($this->tigist->notifications()->sole()->type)->toBe('membership_frozen')
        ->and($this->pushes->sent[0][1]->body)->toBe('Your Monthly membership at Demo Gym is frozen from 2026-10-26 to 2026-11-01. It now ends on 2026-11-30.');
});

it('tells a member they were turned away at the door, and why', function () {
    $this->membership->forceFill(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-23'])->save();
    $credential = AccessCredential::factory()->for($this->tigist)->create();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.check-ins.scan', ['organization' => 'demo-gym']), [
        'payload' => Gq1::payload($credential->rawSecret(), $credential->publicId(), Gq1::stepAt(CarbonImmutable::now()->getTimestamp())),
        'branch_id' => $this->mainBranch->id,
    ])->assertJsonPath('data.reason_code', 'MEMBERSHIP_EXPIRED');

    expect($this->tigist->notifications()->sole()->type)->toBe('visit_denied')
        ->and($this->pushes->sent[0][1]->title)->toBe('Check-in refused')
        ->and($this->pushes->sent[0][1]->body)->toBe('You couldn\'t check in at Main Branch: your membership has ended.')
        ->and($this->pushes->sent[0][1]->data['reason_code'])->toBe('MEMBERSHIP_EXPIRED');
});

it('tells no one about a scan of a code nobody can be matched to', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.check-ins.scan', ['organization' => 'demo-gym']), [
        'payload' => 'hello',
        'branch_id' => $this->mainBranch->id,
    ])->assertJsonPath('data.reason_code', 'CREDENTIAL_INVALID');

    expect(DB::table('notifications')->count())->toBe(0)
        ->and($this->pushes->sent)->toBe([]);
});

it('keeps the inbox but sends no push about a topic the member turned off', function () {
    $this->tigist->choosePushes(['payments' => false]);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.memberships.payments.store', ['organization' => 'demo-gym', 'membership' => $this->membership]), [
        'amount' => '1,000',
        'method' => 'cash',
        'branch_id' => $this->mainBranch->id,
    ])->assertCreated();

    expect($this->tigist->notifications()->count())->toBe(1)
        ->and($this->pushes->sent)->toBe([]);
});
