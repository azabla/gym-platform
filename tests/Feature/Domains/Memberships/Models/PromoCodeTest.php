<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->gym = actingAsOrganization(Organization::factory()->create());
    $branch = Branch::factory()->for($this->gym)->create();
    $this->hana = Member::factory()->for($branch, 'homeBranch')->create();
    $this->abebe = Member::factory()->for($branch, 'homeBranch')->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->yearly = Plan::factory()->for($this->gym)->create(['name' => 'Yearly', 'price' => Money::of('24,000')]);
    $this->seller = Staff::factory()->for($this->gym)->create();
    $this->today = CarbonImmutable::parse('2026-12-20');
});

it('is found however it is typed, and kept in capitals', function () {
    PromoCode::factory()->for($this->gym)->create(['code' => 'newyear']);

    expect(PromoCode::findByCode('  NewYear ')?->code)->toBe('NEWYEAR')
        ->and(PromoCode::findByCode('NEWYEAR2'))->toBeNull();
});

it('is never found once archived, and its text stays taken', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR']);
    $code->delete();

    expect(PromoCode::findByCode('NEWYEAR'))->toBeNull()
        ->and(PromoCode::codeTakenBy('newyear')?->is($code))->toBeTrue();
});

it('takes a percentage or an amount off the plan\'s price', function (string $discount, string $price) {
    $code = PromoCode::factory()->for($this->gym)->create(['discount' => $discount]);

    expect($code->priceFor($this->monthly))->toEqual(Money::of($price));
})->with([
    '20%' => ['20%', '2,000'],
    '500 off' => ['500', '2,000'],
    'free' => ['100%', '0'],
]);

it('sells a membership at the code\'s price, naming the code', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => '20%']);

    $membership = Membership::sell($this->hana, $this->monthly, $this->today, $this->seller, promoCode: $code)->refresh();

    expect($membership->list_price)->toEqual(Money::of('2,500'))
        ->and($membership->price)->toEqual(Money::of('2,000'))
        ->and($membership->discount_reason)->toBe('Promo code NEWYEAR')
        ->and($membership->promoCode->is($code))->toBeTrue()
        ->and($code->usesCount())->toBe(1);
});

it('records a plan bought in the app with a code, with the list price when buying started', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => '20%']);

    $membership = Membership::buyInApp($this->hana, $this->monthly, $this->today, Money::of('2,000'), $code, Money::of('2,500'))->refresh();

    expect($membership->list_price)->toEqual(Money::of('2,500'))
        ->and($membership->price)->toEqual(Money::of('2,000'))
        ->and($membership->discount_reason)->toBe('Promo code NEWYEAR')
        ->and($membership->promo_code_id)->toBe($code->id)
        ->and($membership->sold_in_app)->toBeTrue();
});

it('can be used within its dates, both days included', function (string $today, ?string $refusal) {
    $code = PromoCode::factory()->for($this->gym)->create(['starts_on' => '2026-12-20', 'ends_on' => '2027-01-10']);

    expect($code->refusalToUse($this->hana, $this->monthly, CarbonImmutable::parse($today))?->getMessage())->toBe($refusal);
})->with([
    'the day before' => ['2026-12-19', 'This promo code can be used from 20 December 2026.'],
    'the first day' => ['2026-12-20', null],
    'the last day' => ['2027-01-10', null],
    'the day after' => ['2027-01-11', 'This promo code ended on 10 January 2027.'],
]);

it('is used up after its total, cancelled memberships counting', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['max_uses' => 2]);
    Membership::sell($this->abebe, $this->monthly, $this->today, $this->seller, promoCode: $code)->cancel($this->seller, 'Changed mind');
    $other = Member::factory()->for($this->hana->homeBranch, 'homeBranch')->create();

    expect($code->refusalToUse($this->hana, $this->monthly, $this->today))->toBeNull();

    Membership::sell($other, $this->monthly, $this->today, $this->seller, promoCode: $code);

    expect($code->refusalToUse($this->hana, $this->monthly, $this->today)?->getMessage())->toBe('This promo code has been used up.');
});

it('can be used once per member, unless the gym allows more', function () {
    $code = PromoCode::factory()->for($this->gym)->create();
    Membership::sell($this->hana, $this->monthly, $this->today, $this->seller, promoCode: $code);

    expect($code->refusalToUse($this->hana, $this->yearly, $this->today)?->getMessage())->toContain('once per member')
        ->and($code->refusalToUse($this->abebe, $this->yearly, $this->today))->toBeNull();

    $code->update(['once_per_member' => false]);

    expect($code->refusalToUse($this->hana, $this->yearly, $this->today))->toBeNull();
});

it('is for every plan, or only the plans the gym chose', function () {
    $code = PromoCode::factory()->for($this->gym)->create();
    $code->makeAvailableOnlyFor($this->yearly);

    expect($code->refusalToUse($this->hana, $this->monthly, $this->today)?->getMessage())->toBe('This promo code isn\'t for the Monthly plan.')
        ->and($code->refusalToUse($this->hana, $this->yearly, $this->today))->toBeNull()
        ->and($code->limitedToPlanIds())->toBe([$this->yearly->id]);

    $code->makeAvailableForAllPlans();

    expect($code->refusalToUse($this->hana, $this->monthly, $this->today))->toBeNull();
});

it('is refused for a plan it takes more off than it costs', function () {
    $code = PromoCode::factory()->for($this->gym)->create(['discount' => '3,000']);

    expect($code->refusalToUse($this->hana, $this->monthly, $this->today)?->getMessage())->toBe('This promo code takes more off than the Monthly plan costs.')
        ->and($code->refusalToUse($this->hana, $this->yearly, $this->today))->toBeNull();
});

it('can\'t be linked to another gym\'s plan', function () {
    $code = PromoCode::factory()->for($this->gym)->create();
    $otherGym = Organization::factory()->create();
    $otherPlan = app(CurrentOrganization::class)->run($otherGym, fn (): Plan => Plan::factory()->for($otherGym)->create());

    expect(fn () => $code->plans()->attach($otherPlan->id, ['organization_id' => $this->gym->id]))->toThrow(QueryException::class);
});
