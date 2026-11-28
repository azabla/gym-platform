<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Exceptions\BillingRefused;
use App\Domains\PlatformBilling\GymBills;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $this->plan = PlatformPlan::factory()->create(['name' => 'Standard']);
});

it('leaves a gym unbilled until billing is set up', function () {
    expect(GymSubscription::of($this->gym))->toBeNull()
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::NotBilled);
});

it('starts with a free trial, billing from the day after it ends', function () {
    $subscription = GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), trialDays: GymSubscription::DEFAULT_TRIAL_DAYS);

    expect($subscription->trial_ends_on?->toDateString())->toBe('2026-12-27')
        ->and($subscription->next_period_starts_on->toDateString())->toBe('2026-12-28')
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::Trial);

    $this->travelTo(CarbonImmutable::parse('2026-12-28 00:30', 'Africa/Addis_Ababa'));

    expect(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::PaidUp);
});

it('starts without a trial on the day chosen, never a day already past where the gym is', function () {
    $subscription = GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), firstBillingDay: CarbonImmutable::parse('2026-12-01'));

    expect($subscription->trial_ends_on)->toBeNull()
        ->and($subscription->next_period_starts_on->toDateString())->toBe('2026-12-01');

    $other = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);

    expect(fn () => GymSubscription::start($other, new SubscriptionTerms($this->plan), firstBillingDay: CarbonImmutable::parse('2026-11-27')))
        ->toThrow(BillingRefused::class, 'can\'t be before today');
});

it('refuses to bill a gym twice or on a retired plan', function () {
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan));

    expect(fn () => GymSubscription::start($this->gym, new SubscriptionTerms($this->plan)))->toThrow(BillingRefused::class, 'already has billing');

    $other = Organization::factory()->create();
    $retired = PlatformPlan::factory()->retired()->create();

    expect(fn () => GymSubscription::start($other, new SubscriptionTerms($retired)))->toThrow(BillingRefused::class, 'retired');
});

it('charges negotiated prices, for the branches the gym has, for the months each invoice covers', function () {
    $this->plan->update(['discount_3_months' => 10]);
    Branch::factory()->for($this->gym)->count(2)->create();

    $subscription = GymSubscription::start($this->gym, new SubscriptionTerms($this->plan, monthlyPriceOverride: Money::of('1,800'), monthsPerInvoice: 3));

    expect($subscription->hasNegotiatedPrices())->toBeTrue()
        ->and($subscription->monthlyPrice()->toDecimal())->toBe('1800.00')
        ->and($subscription->extraBranchPrice()->toDecimal())->toBe('500.00')
        ->and($subscription->chargeNow()->total->toDecimal())->toBe('6210.00');
});

it('changes the terms without moving the next period, and keeps a retired plan the gym is already on', function () {
    $subscription = GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), firstBillingDay: CarbonImmutable::parse('2026-12-01'));
    $this->plan->retire();

    $subscription->change(new SubscriptionTerms($this->plan->refresh(), monthsPerInvoice: 12, graceDays: 7, tin: '0012345678'));

    expect($subscription->refresh()->months_per_invoice)->toBe(12)
        ->and($subscription->grace_days)->toBe(7)
        ->and($subscription->tin)->toBe('0012345678')
        ->and($subscription->next_period_starts_on->toDateString())->toBe('2026-12-01');

    $retired = PlatformPlan::factory()->retired()->create();

    expect(fn () => $subscription->change(new SubscriptionTerms($retired)))->toThrow(BillingRefused::class, 'retired');
});

it('extends a trial, moving the first billing day with it, only while it lasts', function () {
    $subscription = GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), trialDays: 30);

    $subscription->extendTrial($this->gym, CarbonImmutable::parse('2027-01-10'));

    expect($subscription->refresh()->next_period_starts_on->toDateString())->toBe('2027-01-11');
    expect(fn () => $subscription->extendTrial($this->gym, CarbonImmutable::parse('2027-01-05')))->toThrow(BillingRefused::class, 'after the trial');

    $this->travelTo(CarbonImmutable::parse('2027-01-11 09:00', 'Africa/Addis_Ababa'));

    expect(fn () => $subscription->extendTrial($this->gym, CarbonImmutable::parse('2027-02-01')))->toThrow(BillingRefused::class, 'isn\'t on its trial');
});

it('keeps a plan in use from being deleted', function () {
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan));

    expect(fn () => $this->plan->delete())->toThrow(QueryException::class);
});
