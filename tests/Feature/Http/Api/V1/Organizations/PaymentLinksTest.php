<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
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
    $this->member = Member::factory()->for($this->mainBranch, 'homeBranch')->create([
        'full_name' => 'Hana Girma',
        'phone' => '0933 45 67 89',
        'email' => 'hana@example.com',
    ]);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->membership = Membership::factory()->for($this->member)->for($this->monthly)->create();
    $this->chapaAccount = ChapaAccount::factory()->for($this->gym)->create(['secret_key' => 'CHASECK_TEST-demogymkey']);
});

/**
 * POST a payment link of the given details for the membership.
 *
 * @param  array<string, mixed>  $details
 */
function createPaymentLink(Membership $membership, array $details = []): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.memberships.payment-links.store', ['organization' => 'demo-gym', 'membership' => $membership]),
        $details,
    );
}

it('lets a receptionist create a link for what the member still owes', function () {
    Payment::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    fakeChapaCheckout();
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    createPaymentLink($this->membership)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount.amount_minor', 150000)
        ->assertJsonPath('data.checkout_url', 'https://checkout.chapa.co/checkout/payment/hana123')
        ->assertJsonPath('data.branch.name', 'Main Branch')
        ->assertJsonPath('data.member.full_name', 'Hana Girma')
        ->assertJsonPath('data.membership.balance_due.amount_minor', 150000)
        ->assertJsonPath('data.created_by.id', $receptionist->id)
        ->assertJsonPath('data.cancellation', null);

    $paymentLink = PaymentLink::query()->acrossOrganizations()->sole();

    expect($paymentLink->tx_ref)->toStartWith('GP-');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.chapa.co/v1/transaction/initialize'
        && $request->hasHeader('Authorization', 'Bearer CHASECK_TEST-demogymkey')
        && $request['amount'] === '1500.00'
        && $request['currency'] === 'ETB'
        && $request['tx_ref'] === $paymentLink->tx_ref
        && $request['first_name'] === 'Hana'
        && $request['last_name'] === 'Girma'
        && $request['phone_number'] === '0933456789'
        && $request['email'] === 'hana@example.com'
        && str_ends_with($request['callback_url'], '/api/v1/chapa/callback')
        && $request['customization'] === ['title' => 'Demo Gym', 'description' => 'Monthly membership']);
});

it('asks Chapa for the amount staff choose, but not for more than is due', function () {
    fakeChapaCheckout();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    createPaymentLink($this->membership, ['amount' => '3,000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Only 2,500.00 ETB is still due on this membership.']);

    Http::assertNothingSent();

    createPaymentLink($this->membership, ['amount' => '500'])
        ->assertCreated()
        ->assertJsonPath('data.amount.amount_minor', 50000);
});

it('refuses a link when nothing is due, or for a cancelled membership', function () {
    Payment::factory()->for($this->membership)->create();
    $cancelled = Membership::factory()->for($this->member)->cancelled()->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
    Http::fake();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    createPaymentLink($this->membership)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Nothing is due on this membership.']);

    createPaymentLink($cancelled)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership' => 'This membership is cancelled, so nothing is due on it.']);

    Http::assertNothingSent();
});

it('allows one open link per membership at a time', function () {
    PaymentLink::factory()->for($this->membership)->create();
    Http::fake();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    createPaymentLink($this->membership)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership' => 'This membership already has an open payment link.']);

    Http::assertNothingSent();
});

it('needs the gym to connect its Chapa account first', function () {
    $this->chapaAccount->delete();
    Http::fake();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    createPaymentLink($this->membership)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapa_account' => 'Connect the gym\'s Chapa account first.']);

    Http::assertNothingSent();
});

it('saves nothing when Chapa refuses the checkout', function () {
    Http::fake(['api.chapa.co/v1/transaction/initialize' => Http::response([
        'message' => ['phone_number' => ['The phone number must be 10 digits.']],
        'status' => 'failed',
        'data' => null,
    ], 400)]);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    createPaymentLink($this->membership)
        ->assertStatus(502)
        ->assertJsonPath('message', 'Chapa refused the request: The phone number must be 10 digits.');

    expect(PaymentLink::query()->acrossOrganizations()->exists())->toBeFalse();
});

it('does not let trainers create, see or check payment links', function () {
    $paymentLink = PaymentLink::factory()->for($this->membership)->create();
    Http::fake();
    actingAsStaff($this->gym, StaffRole::Trainer);

    createPaymentLink($this->membership)->assertForbidden();

    $this->getJson(route('api.v1.organizations.payment-links.index', ['organization' => 'demo-gym']))
        ->assertForbidden();

    $this->getJson(route('api.v1.organizations.payment-links.show', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertForbidden();

    $this->postJson(route('api.v1.organizations.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertForbidden();

    Http::assertNothingSent();
});

it('lists the gym\'s payment links newest first, filtered by status and member', function () {
    $abebesMembership = Membership::factory()->for(Member::factory()->for($this->mainBranch, 'homeBranch'))->create();
    $cancelled = PaymentLink::factory()->for($this->membership)->cancelled()->create(['created_at' => '2026-10-20 10:00:00']);
    $abebesLink = PaymentLink::factory()->for($abebesMembership)->create(['created_at' => '2026-10-21 10:00:00']);
    $open = PaymentLink::factory()->for($this->membership)->create(['created_at' => '2026-10-22 10:00:00']);
    PaymentLink::factory()->create();
    actingAsStaff($this->gym, StaffRole::Accountant);

    $idsListedFor = fn (array $filters) => $this->getJson(route('api.v1.organizations.payment-links.index', ['organization' => 'demo-gym', ...$filters]))
        ->assertOk()
        ->json('data.*.id');

    expect($idsListedFor([]))->toBe([$open->id, $abebesLink->id, $cancelled->id])
        ->and($idsListedFor(['status' => 'pending']))->toBe([$open->id, $abebesLink->id])
        ->and($idsListedFor(['member_id' => $this->member->id]))->toBe([$open->id, $cancelled->id]);

    $this->getJson(route('api.v1.organizations.payment-links.index', ['organization' => 'demo-gym', 'status' => 'expired']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('shows a payment link of the gym, but not one of another gym', function () {
    $paymentLink = PaymentLink::factory()->for($this->membership)->create(['amount' => Money::of('1,000')]);
    $otherGymsLink = PaymentLink::factory()->create();
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->getJson(route('api.v1.organizations.payment-links.show', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertOk()
        ->assertJsonPath('data.amount.formatted', '1,000.00 ETB')
        ->assertJsonPath('data.checkout_url', $paymentLink->checkout_url)
        ->assertJsonPath('data.membership.plan.name', 'Monthly');

    $this->getJson(route('api.v1.organizations.payment-links.show', ['organization' => 'demo-gym', 'payment_link' => $otherGymsLink]))
        ->assertNotFound();
});

it('lets staff cancel an open link and then create a new one', function () {
    $paymentLink = PaymentLink::factory()->for($this->membership)->create();
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    $this->postJson(route('api.v1.organizations.payment-links.cancel', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.checkout_url', null)
        ->assertJsonPath('data.cancellation.cancelled_by.id', $receptionist->id);

    $this->postJson(route('api.v1.organizations.payment-links.cancel', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['payment_link' => 'Only an open link can be cancelled.']);

    fakeChapaCheckout();

    createPaymentLink($this->membership)->assertCreated();
});

it('lets staff ask Chapa about a link, and records the payment once the member has paid', function () {
    $paymentLink = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->mainBranch->id]);
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => Http::sequence()
        ->push(['message' => 'Invalid transaction or Transaction not found', 'status' => 'failed', 'data' => null], 404)
        ->push(chapaPaidAnswer($paymentLink))]);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    $checkWithChapa = fn (): TestResponse => $this->postJson(
        route('api.v1.organizations.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]),
    );

    $checkWithChapa()
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.checkout_url', $paymentLink->checkout_url)
        ->assertJsonPath('data.payment', null);

    $checkWithChapa()
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.checkout_url', null)
        ->assertJsonPath('data.chapa_reference', 'APfqvRZJ0bEn')
        ->assertJsonPath('data.payment.receipt_number', 1)
        ->assertJsonPath('data.payment.paid_on', '2026-10-23')
        ->assertJsonPath('data.membership.balance_due.amount_minor', 0);

    $this->getJson(route('api.v1.organizations.payments.index', ['organization' => 'demo-gym', 'method' => 'chapa']))
        ->assertOk()
        ->assertJsonPath('data.0.reference', 'APfqvRZJ0bEn')
        ->assertJsonPath('data.0.received_by', null)
        ->assertJsonPath('meta.totals.by_method.chapa.amount_minor', 250000);
});

it('needs the gym\'s Chapa account to check a link', function () {
    $paymentLink = PaymentLink::factory()->for($this->membership)->create();
    $this->chapaAccount->delete();
    Http::fake();
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    $this->postJson(route('api.v1.organizations.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapa_account' => 'Connect the gym\'s Chapa account first.']);

    Http::assertNothingSent();
});

it('keeps the link open, and says so, when Chapa does not answer', function () {
    $paymentLink = PaymentLink::factory()->for($this->membership)->create();
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => Http::response(null, 503)]);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->mainBranch);

    $this->postJson(route('api.v1.organizations.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLink]))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Chapa didn\'t answer. Try again in a moment.');

    expect(PaymentLink::query()->acrossOrganizations()->sole()->status)->toBe(PaymentLinkStatus::Pending);
});
