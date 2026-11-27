<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Exceptions\MembershipRefused;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00', 'Africa/Addis_Ababa'));
    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->branch = Branch::factory()->for($this->gym)->create();
    $this->member = Member::factory()->for($this->branch, 'homeBranch')->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['price' => Money::of('2,500'), 'duration_count' => 1, 'duration_unit' => DurationUnit::Month]);
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    $this->today = $this->gym->today();
});

it('brings in a membership that started before the gym joined, at the plan\'s price, with what was paid', function () {
    $membership = Membership::bringIn($this->member, $this->monthly, CarbonImmutable::parse('2026-11-10'), null, $this->owner);
    $payment = Payment::bringIn($membership, Money::of('1,000'), $this->branch, $this->owner);

    expect($membership->ends_on->toDateString())->toBe('2026-12-09')
        ->and($membership->price->format())->toBe('2,500.00 ETB')
        ->and($membership->sold_by_staff_id)->toBe($this->owner->id)
        ->and($payment->method)->toBe(PaymentMethod::BroughtIn)
        ->and($payment->paid_on->toDateString())->toBe('2026-11-10')
        ->and($payment->reference)->toBeNull()
        ->and(Payment::balanceOf($membership)->balanceDue->format())->toBe('1,500.00 ETB');
});

it('keeps an end date that differs from the plan\'s length', function () {
    $membership = Membership::bringIn($this->member, $this->monthly, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-01-31'), $this->owner);

    expect($membership->ends_on->toDateString())->toBe('2027-01-31');
});

it('refuses a membership that ends before it starts, or clashes with another', function () {
    expect(Membership::refusalToBringIn($this->member, $this->monthly, CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-01'), $this->today))
        ->toBeInstanceOf(MembershipRefused::class)
        ->and(Membership::refusalToBringIn($this->member, $this->monthly, CarbonImmutable::parse('2026-11-10'), null, $this->today))->toBeNull();

    Membership::bringIn($this->member, $this->monthly, CarbonImmutable::parse('2026-11-10'), null, $this->owner);

    expect(Membership::refusalToBringIn($this->member, $this->monthly, CarbonImmutable::parse('2026-11-20'), null, $this->today)?->getMessage())
        ->toStartWith('The member already has a membership from 10 November 2026 to 9 December 2026.');
});

it('refuses to bring in more than the membership costs', function () {
    $membership = Membership::bringIn($this->member, $this->monthly, CarbonImmutable::parse('2026-11-10'), null, $this->owner);

    expect(fn () => Payment::bringIn($membership, Money::of('3,000'), $this->branch, $this->owner))->toThrow(PaymentRefused::class)
        ->and(Payment::query()->count())->toBe(0);
});
