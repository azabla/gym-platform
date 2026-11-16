<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Memberships\Pricing\Pricing;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->gym = actingAsOrganization(Organization::factory()->create());
    $this->tigist = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->staff = Staff::factory()->for($this->gym)->create();
    $this->student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student', 'discount' => '10%']);
    $this->today = CarbonImmutable::parse('2026-12-20');
});

it('charges the plan\'s price to a member in no group, without a code', function () {
    $choice = Pricing::bestFor($this->tigist, $this->monthly, $this->today);

    expect($choice->price)->toEqual(Money::of('2,500'))
        ->and($choice->isDiscounted())->toBeFalse();
});

it('charges a member their group\'s price while it applies', function (?string $until, string $price) {
    MemberDiscount::assign($this->tigist, $this->student, $until === null ? null : CarbonImmutable::parse($until), $this->staff);

    expect(Pricing::bestFor($this->tigist, $this->monthly, $this->today)->price)->toEqual(Money::of($price));
})->with([
    'with no end' => [null, '2,250'],
    'until today' => ['2026-12-20', '2,250'],
    'until yesterday' => ['2026-12-19', '2,500'],
]);

it('stops a group applying once it is archived', function () {
    MemberDiscount::assign($this->tigist, $this->student, null, $this->staff);
    $this->student->delete();

    expect(Pricing::bestFor($this->tigist, $this->monthly, $this->today)->isDiscounted())->toBeFalse();
});

it('charges the better of the group and a promo code, never both', function (string $codeDiscount, string $price, string $source) {
    MemberDiscount::assign($this->tigist, $this->student, null, $this->staff);
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => $codeDiscount]);

    $choice = Pricing::bestFor($this->tigist, $this->monthly, $this->today, $code);

    expect($choice->price)->toEqual(Money::of($price))
        ->and($choice->promoCode !== null ? 'code' : 'group')->toBe($source);
})->with([
    'the code is better' => ['20%', '2,000', 'code'],
    'the group is better' => ['5%', '2,250', 'group'],
    'a tie goes to the group' => ['250', '2,250', 'group'],
]);

it('sells at the group\'s price, naming the group', function () {
    MemberDiscount::assign($this->tigist, $this->student, null, $this->staff);

    $membership = Membership::sellAt(Pricing::bestFor($this->tigist, $this->monthly, $this->today), $this->tigist, $this->monthly, $this->today, $this->staff)->refresh();

    expect($membership->price)->toEqual(Money::of('2,250'))
        ->and($membership->list_price)->toEqual(Money::of('2,500'))
        ->and($membership->discount_reason)->toBe('Discount group Student')
        ->and($membership->discount_group_id)->toBe($this->student->id)
        ->and($membership->promo_code_id)->toBeNull();
});

it('records a plan bought in the app at the group\'s price', function () {
    $membership = Membership::buyInApp($this->tigist, $this->monthly, $this->today, Money::of('2,250'), null, Money::of('2,500'), $this->student)->refresh();

    expect($membership->list_price)->toEqual(Money::of('2,500'))
        ->and($membership->discount_reason)->toBe('Discount group Student')
        ->and($membership->discount_group_id)->toBe($this->student->id);
});

it('keeps a member in one group, and their group names unique among active groups', function () {
    $staffFamily = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Staff family', 'discount' => '50%']);
    MemberDiscount::assign($this->tigist, $this->student, null, $this->staff);
    MemberDiscount::assign($this->tigist, $staffFamily, null, $this->staff);

    expect(MemberDiscount::query()->count())->toBe(1)
        ->and(MemberDiscount::groupFor($this->tigist, $this->today)?->is($staffFamily))->toBeTrue()
        ->and(DiscountGroup::nameTakenBy('STUDENT')?->is($this->student))->toBeTrue();

    MemberDiscount::remove($this->tigist);

    expect(MemberDiscount::groupFor($this->tigist, $this->today))->toBeNull();

    DiscountGroup::factory()->for($this->gym)->create(['name' => 'student']);
})->throws(QueryException::class, 'unique');
