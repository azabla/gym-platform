<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create();
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Tigist Haile']);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->chapaAccount = ChapaAccount::factory()->for($this->gym)->create(['secret_key' => 'CHASECK_TEST-demogymkey']);
});

/**
 * At the gym: open a link for Tigist to buy the plan, as the app does.
 */
function openPurchase(Plan $plan): PaymentLink
{
    fakeChapaCheckout();

    return app(CurrentOrganization::class)->run(test()->gym, fn () => PaymentLink::openForPlan(
        test()->tigist, $plan, test()->mainBranch, test()->chapaAccount, 'https://gym.test/callback',
    ));
}

/**
 * At the gym: Chapa says the link was paid in full, and we ask it.
 */
function payPurchase(PaymentLink $paymentLink): PaymentLink
{
    fakeChapaPaid($paymentLink);

    return app(CurrentOrganization::class)->run(test()->gym, function () use ($paymentLink) {
        $paymentLink->check(test()->chapaAccount);

        return $paymentLink;
    });
}

/**
 * The membership the member bought with the link.
 */
function boughtWith(PaymentLink $paymentLink): Membership
{
    return Membership::query()->acrossOrganizations()->findOrFail($paymentLink->membership_id);
}

it('opens a link for the plan at its price, and creates nothing until it is paid', function () {
    $paymentLink = openPurchase($this->monthly);

    expect($paymentLink)
        ->status->toBe(PaymentLinkStatus::Pending)
        ->member_id->toBe($this->tigist->id)
        ->plan_id->toBe($this->monthly->id)
        ->membership_id->toBeNull()
        ->and($paymentLink->amount->equals(Money::of('2,500')))->toBeTrue()
        ->and(Membership::query()->acrossOrganizations()->count())->toBe(0);
});

it('creates the membership once Chapa confirms the payment, starting the day after the current one ends', function () {
    Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-10-06', 'ends_on' => '2026-11-05']);

    $paymentLink = payPurchase(openPurchase($this->monthly));

    $bought = Membership::query()->acrossOrganizations()->latest('id')->first();
    expect($paymentLink->status)->toBe(PaymentLinkStatus::Paid)
        ->and($paymentLink->membership_id)->toBe($bought->id)
        ->and($bought->starts_on->toDateString())->toBe('2026-11-06')
        ->and($bought->ends_on->toDateString())->toBe('2026-12-05')
        ->and($bought->sold_in_app)->toBeTrue()
        ->and($bought->sold_by_staff_id)->toBeNull()
        ->and($bought->price->equals(Money::of('2,500')))->toBeTrue();

    $payment = Payment::query()->acrossOrganizations()->sole();
    expect($payment->membership_id)->toBe($bought->id)
        ->and($payment->method)->toBe(PaymentMethod::Chapa)
        ->and($payment->id)->toBe($paymentLink->payment_id);
});

it('starts today when the member\'s last membership has ended', function () {
    Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);

    payPurchase(openPurchase($this->monthly));

    expect(Membership::query()->acrossOrganizations()->latest('id')->first()->starts_on->toDateString())->toBe('2026-10-24');
});

it('queues the bought membership after one sold at the desk meanwhile', function () {
    $paymentLink = openPurchase($this->monthly);
    $soldAtTheDesk = Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-10-24', 'ends_on' => '2026-11-23']);

    payPurchase($paymentLink);

    expect(boughtWith($paymentLink)->starts_on->toDateString())->toBe('2026-11-24')
        ->and($paymentLink->membership_id)->not->toBe($soldAtTheDesk->id);
});

it('sells at the price paid when the plan\'s price changed meanwhile', function () {
    $paymentLink = openPurchase($this->monthly);
    $this->monthly->forceFill(['price' => Money::of('3,000')])->save();

    payPurchase($paymentLink);

    expect(boughtWith($paymentLink)->price->equals(Money::of('2,500')))->toBeTrue()
        ->and(boughtWith($paymentLink)->list_price->equals(Money::of('2,500')))->toBeTrue();
});

it('sells nothing to a member who left the gym meanwhile, and marks a refund needed', function () {
    $paymentLink = openPurchase($this->monthly);
    $this->tigist->delete();

    payPurchase($paymentLink);

    expect($paymentLink->status)->toBe(PaymentLinkStatus::RefundNeeded)
        ->and(Membership::query()->acrossOrganizations()->count())->toBe(0)
        ->and(Payment::query()->acrossOrganizations()->count())->toBe(0);
});

it('sells once, however often Chapa tells us', function () {
    $paymentLink = payPurchase(openPurchase($this->monthly));
    payPurchase($paymentLink);

    expect(Membership::query()->acrossOrganizations()->count())->toBe(1)
        ->and(Payment::query()->acrossOrganizations()->count())->toBe(1);
});

it('refuses a link for neither a membership nor a plan', function () {
    PaymentLink::factory()->create(['membership_id' => null, 'member_id' => $this->tigist->id, 'organization_id' => $this->gym->id]);
})->throws(QueryException::class, 'violates check constraint');

it('refuses a second open purchase link for a member', function () {
    $purchase = ['membership_id' => null, 'member_id' => $this->tigist->id, 'plan_id' => $this->monthly->id, 'organization_id' => $this->gym->id];
    PaymentLink::factory()->create($purchase);

    PaymentLink::factory()->create($purchase);
})->throws(QueryException::class, 'violates unique constraint');

it('refuses a membership with no one who sold it that was not bought in the app', function () {
    Membership::factory()->create(['sold_by_staff_id' => null]);
})->throws(QueryException::class, 'violates check constraint');
