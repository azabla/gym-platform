<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Events\GymInvoiceIssued;
use App\Domains\PlatformBilling\Exceptions\BillingRefused;
use App\Domains\PlatformBilling\GymBills;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    Branch::factory()->for($this->gym)->create();
    $this->plan = PlatformPlan::factory()->create(['name' => 'Standard']);
});

function runBilling(): void
{
    test()->artisan('platform-billing:run')->assertSuccessful();
}

/**
 * @return list<GymInvoice>
 */
function invoicesOf(Organization $gym): array
{
    return array_values(GymInvoice::query()->acrossOrganizations()->where('organization_id', $gym->getKey())->orderBy('number')->get()->all());
}

it('issues an invoice 7 days before its period starts, due on its first day, with what it was issued with', function () {
    Event::fake([GymInvoiceIssued::class]);
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan, tin: '0012345678'), firstBillingDay: CarbonImmutable::parse('2026-12-10'));

    runBilling();
    expect(invoicesOf($this->gym))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-12-03 00:10', 'Africa/Addis_Ababa'));
    runBilling();

    $invoice = invoicesOf($this->gym)[0];

    expect($invoice->reference())->toBe('INV-000001')
        ->and($invoice->issued_on->toDateString())->toBe('2026-12-03')
        ->and($invoice->period_starts_on->toDateString())->toBe('2026-12-10')
        ->and($invoice->period_ends_on->toDateString())->toBe('2027-01-09')
        ->and($invoice->due_on->toDateString())->toBe('2026-12-10')
        ->and($invoice->pauses_on->toDateString())->toBe('2026-12-24')
        ->and($invoice->plan_name)->toBe('Standard')
        ->and($invoice->total->toDecimal())->toBe('2000.00')
        ->and($invoice->vat->toDecimal())->toBe('260.87')
        ->and($invoice->tin)->toBe('0012345678')
        ->and(GymSubscription::of($this->gym)?->next_period_starts_on->toDateString())->toBe('2027-01-10')
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::Due);

    Event::assertDispatched(GymInvoiceIssued::class);

    $this->travelTo(CarbonImmutable::parse('2026-12-11 09:00', 'Africa/Addis_Ababa'));

    expect(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::Overdue);
});

it('goes by the gym\'s own date, not the server\'s', function () {
    $farWest = Organization::factory()->create(['timezone' => 'America/Los_Angeles']);
    GymSubscription::start($farWest, new SubscriptionTerms($this->plan), firstBillingDay: CarbonImmutable::parse('2026-12-05'));

    $this->travelTo(CarbonImmutable::parse('2026-11-28 05:00', 'UTC'));
    runBilling();
    expect(invoicesOf($farWest))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-11-28 09:00', 'UTC'));
    runBilling();
    expect(invoicesOf($farWest))->toHaveCount(1);
});

it('never issues an invoice twice, and numbers invoices across the platform without gaps', function () {
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan));
    $other = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    GymSubscription::start($other, new SubscriptionTerms($this->plan));

    runBilling();
    runBilling();

    $numbers = GymInvoice::query()->acrossOrganizations()->orderBy('number')->pluck('number')->map(fn (mixed $number): int => (int) $number)->all();

    expect($numbers)->toBe([1, 2]);
});

it('counts the branches the gym has when the invoice is issued, and the discount for paying ahead', function () {
    $this->plan->update(['discount_12_months' => 10]);
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan, monthsPerInvoice: 12), firstBillingDay: CarbonImmutable::parse('2026-12-01'));
    Branch::factory()->for($this->gym)->count(2)->create();

    runBilling();

    $invoice = invoicesOf($this->gym)[0];

    expect($invoice->branches)->toBe(3)
        ->and($invoice->months)->toBe(12)
        ->and($invoice->discount_percent)->toBe(10)
        ->and($invoice->total->toDecimal())->toBe('32400.00')
        ->and($invoice->period_ends_on->toDateString())->toBe('2027-11-30');
});

it('issues the first invoice in the trial\'s last week, never earlier', function () {
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), trialDays: 30);

    runBilling();
    expect(invoicesOf($this->gym))->toBe([])
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::Trial);

    $this->travelTo(CarbonImmutable::parse('2026-12-21 08:00', 'Africa/Addis_Ababa'));
    runBilling();

    expect(invoicesOf($this->gym)[0]->period_starts_on->toDateString())->toBe('2026-12-28')
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::Due);
    expect(fn () => GymSubscription::of($this->gym)?->extendTrial($this->gym, CarbonImmutable::parse('2027-01-31')))->toThrow(BillingRefused::class, 'Void it first');
});

it('issues nothing while the gym is paused, and starts the period on the day it resumes', function () {
    $admin = User::factory()->create();
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), firstBillingDay: CarbonImmutable::parse('2026-12-01'));
    $this->gym->suspend($admin, 'Closed for renovation');

    runBilling();
    expect(invoicesOf($this->gym))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-12-15 09:00', 'Africa/Addis_Ababa'));
    $this->gym->resume($admin);
    runBilling();

    $invoice = invoicesOf($this->gym)[0];

    expect($invoice->period_starts_on->toDateString())->toBe('2026-12-15')
        ->and($invoice->due_on->toDateString())->toBe('2026-12-15');
});

it('catches up on periods whose invoices were missed while no run happened, from today', function () {
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), firstBillingDay: CarbonImmutable::parse('2026-12-01'));

    $this->travelTo(CarbonImmutable::parse('2027-01-20 09:00', 'Africa/Addis_Ababa'));
    runBilling();

    expect(array_map(fn (GymInvoice $invoice): string => $invoice->period_starts_on->toDateString(), invoicesOf($this->gym)))->toBe(['2027-01-20']);
});

it('voids an invoice with a reason, issuing its period again with the gym\'s new terms', function () {
    $admin = User::factory()->create();
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), firstBillingDay: CarbonImmutable::parse('2026-12-01'));
    runBilling();
    $wrong = invoicesOf($this->gym)[0];

    expect(fn () => $wrong->void($admin, ' '))->toThrow(BillingRefused::class, 'Say why');

    GymSubscription::of($this->gym)?->change(new SubscriptionTerms($this->plan, monthlyPriceOverride: Money::of('1,800')));
    $wrong->void($admin, 'They agreed 1,800 a month.');
    runBilling();

    $invoices = invoicesOf($this->gym);

    expect($invoices)->toHaveCount(2)
        ->and($invoices[0]->refresh()->voided_at)->not->toBeNull()
        ->and($invoices[1]->period_starts_on->toDateString())->toBe('2026-12-01')
        ->and($invoices[1]->total->toDecimal())->toBe('1800.00');
    expect(fn () => $invoices[0]->void($admin, 'again'))->toThrow(BillingRefused::class, 'already voided');
});
