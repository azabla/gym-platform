<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Enums\GymPaymentMethod;
use App\Domains\PlatformBilling\Events\GymInvoicePaid;
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
    $this->admin = User::factory()->create();
    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    GymSubscription::start($this->gym, new SubscriptionTerms(PlatformPlan::factory()->create()));
    $this->artisan('platform-billing:run');
    $this->invoice = GymInvoice::query()->acrossOrganizations()->sole();
    $this->today = CarbonImmutable::parse('2026-11-28');
});

it('takes a part payment, then the rest, and only then is the invoice paid', function () {
    Event::fake([GymInvoicePaid::class]);

    $this->invoice->recordPayment(Money::of('500'), GymPaymentMethod::Telebirr, ' ab 12 cd ', $this->today, $this->admin);

    expect($this->invoice->refresh()->paid_at)->toBeNull()
        ->and($this->invoice->balance()->toDecimal())->toBe('1500.00')
        ->and($this->invoice->payments()->sole()->reference)->toBe('AB12CD')
        ->and(app(GymBills::class)->owedBy([$this->gym])[(int) $this->gym->getKey()]->toDecimal())->toBe('1500.00');
    Event::assertNotDispatched(GymInvoicePaid::class);

    $this->invoice->recordPayment(Money::of('1,500'), GymPaymentMethod::Cash, null, $this->today, $this->admin);

    expect($this->invoice->refresh()->paid_at)->not->toBeNull()
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::PaidUp);
    Event::assertDispatched(GymInvoicePaid::class);
});

it('refuses more than is owed, a used or missing transaction number, a day still to come, and paying a voided invoice', function () {
    expect(fn () => $this->invoice->recordPayment(Money::of('2,000.01'), GymPaymentMethod::Cash, null, $this->today, $this->admin))
        ->toThrow(BillingRefused::class, 'more than is owed');
    expect(fn () => $this->invoice->recordPayment(Money::of('100'), GymPaymentMethod::BankTransfer, '', $this->today, $this->admin))
        ->toThrow(BillingRefused::class, 'transaction number');
    expect(fn () => $this->invoice->recordPayment(Money::of('100'), GymPaymentMethod::Cash, null, $this->today->addDay(), $this->admin))
        ->toThrow(BillingRefused::class, 'after today');

    $this->invoice->recordPayment(Money::of('100'), GymPaymentMethod::BankTransfer, 'FT1', $this->today, $this->admin);

    expect(fn () => $this->invoice->recordPayment(Money::of('100'), GymPaymentMethod::BankTransfer, 'ft 1', $this->today, $this->admin))
        ->toThrow(BillingRefused::class, 'already recorded');
    expect(fn () => $this->invoice->void($this->admin, 'Wrong'))->toThrow(BillingRefused::class, 'has payments');
});

it('gives the platform\'s figures in counts and totals', function () {
    $this->invoice->recordPayment(Money::of('500'), GymPaymentMethod::Cash, null, $this->today, $this->admin);
    $onTrial = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    GymSubscription::start($onTrial, new SubscriptionTerms(PlatformPlan::factory()->create()), trialDays: 30);

    $this->travelTo(CarbonImmutable::parse('2026-11-29 10:00', 'Africa/Addis_Ababa'));
    $figures = app(GymBills::class)->platformFigures();

    expect($figures['owed']->toDecimal())->toBe('1500.00')
        ->and($figures['overdue_gyms'])->toBe(1)
        ->and($figures['collected_this_month']->toDecimal())->toBe('500.00')
        ->and($figures['on_trial'])->toBe(1);
});
