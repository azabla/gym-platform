<?php

declare(strict_types=1);

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create([
        'full_name' => 'Tigist Alemu',
        'phone' => '0922 34 56 78',
    ]);
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->membership = Membership::factory()->for($this->tigist)->for($this->monthly)->create();
    $this->chapaAccount = ChapaAccount::factory()->for($this->gym)->create(['secret_key' => 'CHASECK_TEST-demogymkey']);
});

/**
 * Pay the membership in the members app: ask for its payment link.
 */
function payInTheApp(Membership $membership): TestResponse
{
    return test()->postJson(route('api.v1.me.gyms.memberships.payment-link.store', [
        'organization' => 'demo-gym',
        'membership' => $membership,
    ]));
}

it('creates a link for everything I owe, at my home branch, and gives the same link again', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    fakeChapaCheckout('https://checkout.chapa.co/checkout/payment/tigist123');
    actingAsMember($this->tigist);

    $paymentLinkId = payInTheApp($this->membership)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount.amount_minor', 150000)
        ->assertJsonPath('data.checkout_url', 'https://checkout.chapa.co/checkout/payment/tigist123')
        ->assertJsonPath('data.membership_id', $this->membership->id)
        ->assertJsonPath('data.payment', null)
        ->json('data.id');

    $paymentLink = PaymentLink::query()->acrossOrganizations()->sole();

    expect($paymentLink->branch_id)->toBe($this->mainBranch->id)
        ->and($paymentLink->created_by_staff_id)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.chapa.co/v1/transaction/initialize'
        && $request->hasHeader('Authorization', 'Bearer CHASECK_TEST-demogymkey')
        && $request['amount'] === '1500.00'
        && $request['tx_ref'] === $paymentLink->tx_ref
        && $request['phone_number'] === '0922345678'
        && str_ends_with($request['callback_url'], '/api/v1/chapa/callback'));

    payInTheApp($this->membership)
        ->assertOk()
        ->assertJsonPath('data.id', $paymentLinkId);

    $this->getJson(route('api.v1.me.gyms.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->membership]))
        ->assertJsonPath('data.open_payment_link.id', $paymentLinkId);

    Http::assertSentCount(1);
});

it('gives the open link staff already created, as it is', function () {
    $staffsLink = PaymentLink::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    Http::fake();
    actingAsMember($this->tigist);

    payInTheApp($this->membership)
        ->assertOk()
        ->assertJsonPath('data.id', $staffsLink->id)
        ->assertJsonPath('data.amount.amount_minor', 100000)
        ->assertJsonPath('data.checkout_url', $staffsLink->checkout_url);

    Http::assertNothingSent();
});

it('refuses when nothing is due, the membership is cancelled, or the gym takes no online payments', function () {
    $paidUp = Membership::factory()->for($this->tigist)->for($this->monthly)->create([
        'starts_on' => '2026-12-01',
        'ends_on' => '2026-12-31',
    ]);
    Payment::factory()->for($paidUp)->create();
    $cancelled = Membership::factory()->for($this->tigist)->for($this->monthly)->cancelled()->create([
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-01-31',
    ]);
    Http::fake();
    actingAsMember($this->tigist);

    payInTheApp($paidUp)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership' => 'Nothing is due on this membership.']);

    payInTheApp($cancelled)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership' => 'This membership is cancelled, so nothing is due on it.']);

    $this->chapaAccount->delete();

    payInTheApp($this->membership)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapa_account' => 'This gym doesn\'t take online payments yet.']);

    Http::assertNothingSent();
});

it('refuses an open link that asks for more than is now due', function () {
    PaymentLink::factory()->for($this->membership)->create();
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    Http::fake();
    actingAsMember($this->tigist);

    payInTheApp($this->membership)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership' => 'This membership has an open payment link for more than is due now. Ask the gym to cancel it.']);

    Http::assertNothingSent();
});

it('checks a link with Chapa when I come back from paying, and shows its receipt', function () {
    $paymentLink = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->mainBranch->id]);
    fakeChapaPaid($paymentLink);
    actingAsMember($this->tigist);

    $this->postJson(route('api.v1.me.gyms.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.checkout_url', null)
        ->assertJsonPath('data.payment.receipt_number', 1)
        ->assertJsonPath('data.payment.paid_on', '2026-10-23');

    $this->getJson(route('api.v1.me.gyms.payment-links.show', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $this->getJson(route('api.v1.me.gyms.payments.index', ['organization' => 'demo-gym']))
        ->assertJsonPath('data.0.method', 'chapa')
        ->assertJsonPath('data.0.reference', 'APfqvRZJ0bEn');
});

it('does not let me pay, see or check another member\'s membership or link', function () {
    $abebesMembership = Membership::factory()
        ->for(Member::factory()->for($this->mainBranch, 'homeBranch'))
        ->for($this->monthly)
        ->create();
    $abebesLink = PaymentLink::factory()->for($abebesMembership)->create();
    Http::fake();
    actingAsMember($this->tigist);

    payInTheApp($abebesMembership)->assertNotFound();

    $this->getJson(route('api.v1.me.gyms.payment-links.show', ['organization' => 'demo-gym', 'payment_link' => $abebesLink]))
        ->assertNotFound();

    $this->postJson(route('api.v1.me.gyms.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $abebesLink]))
        ->assertNotFound();

    Http::assertNothingSent();
});
