<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-12-20 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = actingAsOrganization(Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']));
    $branch = Branch::factory()->for($this->gym)->create();
    $this->tigist = Member::factory()->for($branch, 'homeBranch')->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->yearly = Plan::factory()->for($this->gym)->create(['name' => 'Yearly', 'price' => Money::of('24,000')]);
    $this->newYear = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => '20%']);
    ChapaAccount::factory()->for($this->gym)->create();
    actingAsMember($this->tigist);
});

function plansWithCode(string $code): TestResponse
{
    return test()->getJson(route('api.v1.me.gyms.plans.index', ['organization' => 'demo-gym', 'promo_code' => $code]));
}

function buyWithCode(Plan $plan, ?string $code): TestResponse
{
    return test()->postJson(
        route('api.v1.me.gyms.plans.purchase.store', ['organization' => 'demo-gym', 'plan' => $plan->id]),
        $code === null ? [] : ['promo_code' => $code],
    );
}

it('shows each plan\'s price with a code, or null where it doesn\'t apply', function () {
    $this->newYear->makeAvailableOnlyFor($this->yearly);

    plansWithCode(' newyear ')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Monthly')
        ->assertJsonPath('data.0.price_with_code', null)
        ->assertJsonPath('data.1.price_with_code.formatted', '19,200.00 ETB');

    $this->getJson(route('api.v1.me.gyms.plans.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.0.price_with_code', null);
});

it('says why a code can\'t be used', function (Closure $setUp, string $code, string $message) {
    $setUp($this);

    plansWithCode($code)->assertUnprocessable()->assertJsonValidationErrors(['promo_code' => $message]);
})->with([
    'unknown' => [fn () => null, 'SUMMER', 'This promo code doesn\'t exist. Check how it is spelled.'],
    'not started' => [fn ($test) => $test->newYear->update(['starts_on' => '2026-12-25']), 'NEWYEAR', 'This promo code can be used from 25 December 2026.'],
    'already used' => [fn ($test) => Membership::factory()->for($test->tigist)->create(['promo_code_id' => $test->newYear->id]), 'NEWYEAR', 'This promo code can be used once per member, and this member has used it.'],
]);

it('opens a link for the price with the code, and records the discount once paid', function () {
    fakeChapaCheckout();

    $paymentLinkId = buyWithCode($this->monthly, 'newyear')
        ->assertCreated()
        ->assertJsonPath('data.amount.formatted', '2,000.00 ETB')
        ->assertJsonPath('data.list_amount.formatted', '2,500.00 ETB')
        ->assertJsonPath('data.promo_code', 'NEWYEAR')
        ->json('data.id');

    fakeChapaPaid(PaymentLink::query()->acrossOrganizations()->findOrFail($paymentLinkId));
    $this->postJson(route('api.v1.me.gyms.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLinkId]))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $membership = Membership::query()->acrossOrganizations()->sole();
    expect($membership->list_price)->toEqual(Money::of('2,500'))
        ->and($membership->price)->toEqual(Money::of('2,000'))
        ->and($membership->discount_reason)->toBe('Promo code NEWYEAR')
        ->and($membership->promo_code_id)->toBe($this->newYear->id)
        ->and($membership->sold_in_app)->toBeTrue();
});

it('gives the same link for the same plan and code, and a new one for another code or none', function () {
    PromoCode::factory()->for($this->gym)->create(['code' => 'STUDENT', 'discount' => '500']);
    fakeChapaCheckout();

    $withNewYear = buyWithCode($this->monthly, 'NEWYEAR')->assertCreated()->json('data.id');
    buyWithCode($this->monthly, 'newyear')->assertOk()->assertJsonPath('data.id', $withNewYear);

    $withStudent = buyWithCode($this->monthly, 'STUDENT')->assertCreated()->assertJsonPath('data.amount.formatted', '2,000.00 ETB')->json('data.id');
    $withoutCode = buyWithCode($this->monthly, null)->assertCreated()->assertJsonPath('data.amount.formatted', '2,500.00 ETB')->json('data.id');

    expect(PaymentLink::query()->acrossOrganizations()->whereKey([$withNewYear, $withStudent])->pluck('status')->all())
        ->toBe([PaymentLinkStatus::Cancelled, PaymentLinkStatus::Cancelled])
        ->and($withoutCode)->not->toBe($withStudent);
});

it('refuses a code that would make the plan free, or isn\'t for it', function () {
    PromoCode::factory()->for($this->gym)->create(['code' => 'FREEMONTH', 'discount' => '100%']);
    $this->newYear->makeAvailableOnlyFor($this->yearly);

    buyWithCode($this->monthly, 'FREEMONTH')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['promo_code' => 'This promo code makes the plan free. Show it at the desk to get your membership.']);

    buyWithCode($this->monthly, 'NEWYEAR')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['promo_code' => 'This promo code isn\'t for the Monthly plan.']);

    Http::assertNothingSent();
});

it('shows each plan at the member\'s group price, or the code\'s when it is lower', function () {
    $student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student', 'discount' => '10%']);
    MemberDiscount::assign($this->tigist, $student, null, Staff::factory()->for($this->gym)->create());

    $this->getJson(route('api.v1.me.gyms.plans.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.0.your_price.formatted', '2,250.00 ETB')
        ->assertJsonPath('data.0.your_discount', ['source' => 'discount_group', 'name' => 'Student']);

    plansWithCode('NEWYEAR')
        ->assertOk()
        ->assertJsonPath('data.0.your_price.formatted', '2,000.00 ETB')
        ->assertJsonPath('data.0.your_discount', ['source' => 'promo_code', 'name' => 'NEWYEAR']);
});

it('shows full price once the member\'s group has ended for them', function () {
    $student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student', 'discount' => '10%']);
    MemberDiscount::assign($this->tigist, $student, CarbonImmutable::parse('2026-12-19'), Staff::factory()->for($this->gym)->create());

    $this->getJson(route('api.v1.me.gyms.plans.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.0.your_price', null)
        ->assertJsonPath('data.0.your_discount', null);
});

it('buys at the group\'s price, recording the group once paid', function () {
    $student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student', 'discount' => '10%']);
    MemberDiscount::assign($this->tigist, $student, null, Staff::factory()->for($this->gym)->create());
    fakeChapaCheckout();

    $paymentLinkId = buyWithCode($this->monthly, null)
        ->assertCreated()
        ->assertJsonPath('data.amount.formatted', '2,250.00 ETB')
        ->assertJsonPath('data.list_amount.formatted', '2,500.00 ETB')
        ->json('data.id');

    buyWithCode($this->monthly, null)->assertOk()->assertJsonPath('data.id', $paymentLinkId);

    fakeChapaPaid(PaymentLink::query()->acrossOrganizations()->findOrFail($paymentLinkId));
    $this->postJson(route('api.v1.me.gyms.payment-links.check', ['organization' => 'demo-gym', 'payment_link' => $paymentLinkId]))
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $membership = Membership::query()->acrossOrganizations()->sole();
    expect($membership->price)->toEqual(Money::of('2,250'))
        ->and($membership->discount_group_id)->toBe($student->id)
        ->and($membership->discount_reason)->toBe('Discount group Student');
});

it('refuses to sell in the app a plan the member\'s group makes free', function () {
    $staffFamily = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Staff family', 'discount' => '100%']);
    MemberDiscount::assign($this->tigist, $staffFamily, null, Staff::factory()->for($this->gym)->create());

    buyWithCode($this->monthly, null)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan' => 'This plan is free for you. Get it at the desk.']);
});
