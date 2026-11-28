<?php

declare(strict_types=1);

use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\MemberDiscount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->branch = Branch::factory()->for($this->gym)->create();
    $this->staff = Staff::factory()->for($this->gym)->create();
    $this->plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->member = Member::factory()->for($this->branch, 'homeBranch')->create();
});

/**
 * The records of the action, oldest first.
 *
 * @return list<ActivityRecord>
 */
function logged(ActivityAction $action): array
{
    return array_values(ActivityRecord::query()->where('action', $action)->orderBy('id')->get()->all());
}

it('logs a sale at the branch of the member, flagged when backdated or below the plan\'s price', function () {
    $another = fn (): Member => Member::factory()->for($this->branch, 'homeBranch')->create();

    Membership::sell($this->member, $this->plan, CarbonImmutable::parse('2026-11-28'), $this->staff);
    Membership::sell($another(), $this->plan, CarbonImmutable::parse('2026-11-28'), $this->staff, Discount::parse('20%', 'Student'));
    Membership::sell($another(), $this->plan, CarbonImmutable::parse('2026-11-01'), $this->staff);

    [$plain, $discounted, $backdated] = logged(ActivityAction::MembershipSold);

    expect($plain->flagged)->toBeFalse()
        ->and($plain->branch_id)->toBe($this->branch->id)
        ->and($plain->staff_id)->toBe($this->staff->id)
        ->and($plain->details['price'])->toBe('2,500.00 ETB')
        ->and($discounted->flagged)->toBeTrue()
        ->and($discounted->details['price'])->toBe('2,000.00 ETB')
        ->and($discounted->details['discount_reason'])->toBe('Student')
        ->and($backdated->flagged)->toBeTrue()
        ->and($backdated->details['backdated'])->toBeTrue();
});

it('doesn\'t flag memberships an import brings in, though they began long ago', function () {
    Membership::bringIn($this->member, $this->plan, CarbonImmutable::parse('2026-10-01'), null, $this->staff);

    expect(logged(ActivityAction::MembershipSold)[0]->flagged)->toBeFalse();
});

it('logs payments, refunds and voids with who did them, flagging a payment dated before today', function () {
    $membership = Membership::sell($this->member, $this->plan, CarbonImmutable::parse('2026-11-28'), $this->staff);
    $payment = Payment::receive($membership, Money::of('2,500'), PaymentMethod::Cash, null, $this->branch, CarbonImmutable::parse('2026-11-27'), $this->staff);
    $payment->void($this->staff, 'Typed the wrong amount.');

    $received = logged(ActivityAction::PaymentReceived)[0];
    $voided = logged(ActivityAction::PaymentVoided)[0];

    expect($received->flagged)->toBeTrue()
        ->and($received->details['receipt'])->toBe($payment->receipt_number)
        ->and($received->details['paid_on'])->toBe('2026-11-27')
        ->and($voided->flagged)->toBeTrue()
        ->and($voided->details['reason'])->toBe('Typed the wrong amount.');

    $membership->cancel($this->staff, 'Moved away.');
    $paid = Payment::receive(Membership::sell($this->member, $this->plan, CarbonImmutable::parse('2026-12-01'), $this->staff), Money::of('2,500'), PaymentMethod::Cash, null, $this->branch, CarbonImmutable::parse('2026-11-28'), $this->staff);
    $paid->membership->cancel($this->staff, 'Changed their mind.');
    Payment::refund($paid->membership, Money::of('1,000'), PaymentMethod::Cash, null, $this->branch, CarbonImmutable::parse('2026-11-28'), $this->staff);

    expect(logged(ActivityAction::MembershipCancelled))->toHaveCount(2)
        ->and(logged(ActivityAction::PaymentRefunded)[0]->details['amount'])->toBe('1,000.00 ETB');
});

it('logs nothing when the action is refused', function () {
    $membership = Membership::sell($this->member, $this->plan, CarbonImmutable::parse('2026-11-28'), $this->staff);

    expect(fn () => Payment::receive($membership, Money::of('9,000'), PaymentMethod::Cash, null, $this->branch, CarbonImmutable::parse('2026-11-28'), $this->staff))
        ->toThrow(PaymentRefused::class);
    expect(logged(ActivityAction::PaymentReceived))->toBe([]);
});

it('logs freezes by staff, but not ones members make in the app', function () {
    $membership = Membership::sell($this->member, $this->plan, CarbonImmutable::parse('2026-11-28'), $this->staff);
    $membership->freeze(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-05'), 'Travelling', $this->staff);
    $membership->freeze(CarbonImmutable::parse('2026-12-10'), CarbonImmutable::parse('2026-12-11'), 'Ill', null);

    expect(logged(ActivityAction::MembershipFrozen))->toHaveCount(1)
        ->and(logged(ActivityAction::MembershipFrozen)[0]->details['days'])->toBe(5);
});

it('logs discounts given to and taken from a member', function () {
    $group = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Students', 'discount' => '20%']);

    MemberDiscount::assign($this->member, $group, null, $this->staff);
    MemberDiscount::remove($this->member, $this->staff);
    MemberDiscount::remove($this->member, $this->staff);

    expect(logged(ActivityAction::DiscountGiven)[0]->details['group'])->toBe('Students')
        ->and(logged(ActivityAction::DiscountRemoved))->toHaveCount(1);
});

it('logs plan prices, promo codes and discount groups as they are created and their money changes, by the staff signed in', function () {
    app(CurrentStaff::class)->set($this->staff);

    $this->plan->update(['description' => 'Gym floor']);
    $this->plan->update(['price' => Money::of('2,800')]);
    $code = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => '10%']);
    $code->update(['discount' => '25%']);
    $group = DiscountGroup::factory()->for($this->gym)->create(['discount' => '10%']);
    $group->update(['name' => 'Renamed']);

    $priced = logged(ActivityAction::PlanPriced);

    expect($priced)->toHaveCount(2)
        ->and($priced[1]->details)->toMatchArray(['plan' => 'Monthly', 'from' => '2,500.00 ETB', 'to' => '2,800.00 ETB'])
        ->and($priced[1]->staff_id)->toBe($this->staff->id)
        ->and(logged(ActivityAction::PromoCodeSaved)[1]->details)->toMatchArray(['code' => 'NEWYEAR', 'discount' => '25%', 'was_discount' => '10%'])
        ->and(logged(ActivityAction::DiscountGroupSaved))->toHaveCount(1);
});
