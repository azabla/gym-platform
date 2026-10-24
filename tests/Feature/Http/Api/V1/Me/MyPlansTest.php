<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->dayPass = Plan::factory()->for($this->gym)->create([
        'name' => 'Day Pass',
        'price' => Money::of('300'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Day,
    ]);
    Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-10-06', 'ends_on' => '2026-11-05']);
    actingAsMember($this->tigist);
});

/**
 * Start buying the plan, as the members app does.
 */
function buy(Plan $plan): TestResponse
{
    return test()->postJson(route('api.v1.me.gyms.plans.purchase.store', ['organization' => 'demo-gym', 'plan' => $plan->id]));
}

it('lists the plans I can buy at my branch, cheapest first, with the dates they would run', function () {
    $mainOnly = Plan::factory()->for($this->gym)->create(['name' => 'Main Only', 'price' => Money::of('2,000')]);
    $mainOnly->makeAvailableOnlyAt($this->mainBranch);
    $boleOnly = Plan::factory()->for($this->gym)->create(['name' => 'Bole Only', 'price' => Money::of('1,000')]);
    $boleOnly->makeAvailableOnlyAt($this->boleBranch);
    Plan::factory()->for($this->gym)->create(['name' => 'Archived'])->delete();
    Plan::factory()->create(['name' => 'Another Gym']);

    $this->getJson(route('api.v1.me.gyms.plans.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Day Pass', 'Main Only', 'Monthly'])
        ->assertJsonPath('data.2.price.formatted', '2,500.00 ETB')
        ->assertJsonPath('data.2.starts_on', '2026-11-06')
        ->assertJsonPath('data.2.ends_on', '2026-12-05')
        ->assertJsonPath('data.0.ends_on', '2026-11-06');
});

it('opens a Chapa checkout for the plan, and gives the same link when asked again', function () {
    ChapaAccount::factory()->for($this->gym)->create();
    fakeChapaCheckout('https://checkout.chapa.co/checkout/payment/monthly123');

    $first = buy($this->monthly)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount.formatted', '2,500.00 ETB')
        ->assertJsonPath('data.checkout_url', 'https://checkout.chapa.co/checkout/payment/monthly123')
        ->assertJsonPath('data.plan.name', 'Monthly')
        ->assertJsonPath('data.membership_id', null);

    buy($this->monthly)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
});

it('cancels my unpaid link when I choose another plan', function () {
    ChapaAccount::factory()->for($this->gym)->create();
    fakeChapaCheckout();

    $monthly = buy($this->monthly)->assertCreated()->json('data.id');
    $dayPass = buy($this->dayPass)->assertCreated()->assertJsonPath('data.plan.name', 'Day Pass')->json('data.id');

    expect(PaymentLink::query()->acrossOrganizations()->find($monthly)->status)->toBe(PaymentLinkStatus::Cancelled)
        ->and($dayPass)->not->toBe($monthly);
});

it('creates my membership once I have paid, bought in the app', function () {
    ChapaAccount::factory()->for($this->gym)->create();
    fakeChapaCheckout();
    $paymentLinkId = buy($this->monthly)->json('data.id');
    fakeChapaPaid(PaymentLink::query()->acrossOrganizations()->findOrFail($paymentLinkId));

    $membershipId = $this->postJson(route('api.v1.me.gyms.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLinkId]))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.payment.receipt_number', 1)
        ->json('data.membership_id');

    $this->getJson(route('api.v1.me.gyms.memberships.show', ['organization' => 'demo-gym', 'membership' => $membershipId]))
        ->assertOk()
        ->assertJsonPath('data.plan.name', 'Monthly')
        ->assertJsonPath('data.starts_on', '2026-11-06')
        ->assertJsonPath('data.balance_due.formatted', '0.00 ETB')
        ->assertJsonPath('data.sold_in_app', true);
});

it('refuses when the gym takes no online payments, or the plan is not sold at my branch', function () {
    buy($this->monthly)->assertUnprocessable()->assertJsonValidationErrors(['chapa_account']);

    ChapaAccount::factory()->for($this->gym)->create();
    $boleOnly = Plan::factory()->for($this->gym)->create();
    $boleOnly->makeAvailableOnlyAt($this->boleBranch);

    buy($boleOnly)->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'This plan isn\'t sold at your branch. Ask the gym about it.']);
});

it('does not find another gym\'s plan, or an archived one', function () {
    ChapaAccount::factory()->for($this->gym)->create();
    $archived = Plan::factory()->for($this->gym)->create();
    $archived->delete();

    buy(Plan::factory()->create())->assertNotFound();
    buy($archived)->assertNotFound();
});
