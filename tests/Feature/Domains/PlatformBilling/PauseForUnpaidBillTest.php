<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\SuspensionCause;
use App\Domains\Organizations\Models\GymSuspension;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\Enums\GymPaymentMethod;
use App\Domains\PlatformBilling\Events\GymPausedForUnpaidBill;
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
    $this->admin = User::factory()->create(['name' => 'Selam']);
    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    GymSubscription::start($this->gym, new SubscriptionTerms(PlatformPlan::factory()->create()));
    $this->artisan('platform-billing:run');
    $this->invoice = GymInvoice::query()->acrossOrganizations()->sole();
});

function onDay(string $day): void
{
    test()->travelTo(CarbonImmutable::parse($day.' 10:00', 'Africa/Addis_Ababa'));
    test()->artisan('platform-billing:run')->assertSuccessful();
}

it('pauses the gym on the invoice\'s pause day, not before, as the platform, saying which invoice', function () {
    Event::fake([GymPausedForUnpaidBill::class]);

    onDay('2026-12-11');
    expect($this->gym->refresh()->isSuspended())->toBeFalse();

    onDay('2026-12-12');

    $pause = GymSuspension::query()->acrossOrganizations()->sole();

    expect($this->gym->refresh()->isSuspendedForUnpaidBill())->toBeTrue()
        ->and($pause->cause)->toBe(SuspensionCause::UnpaidBill)
        ->and($pause->suspendedBy)->toBeNull()
        ->and($pause->reason)->toBe('Unpaid invoice INV-000001, due 28 Nov 2026.')
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::PausedUnpaid);
    Event::assertDispatched(GymPausedForUnpaidBill::class);
});

it('reopens the gym once the invoice is paid in full', function () {
    onDay('2026-12-12');

    $this->invoice->recordPayment(Money::of('1,000'), GymPaymentMethod::Cash, null, CarbonImmutable::parse('2026-12-12'), $this->admin);
    expect($this->gym->refresh()->isSuspended())->toBeTrue();

    $this->invoice->recordPayment(Money::of('1,000'), GymPaymentMethod::Cash, null, CarbonImmutable::parse('2026-12-12'), $this->admin);

    $pause = GymSuspension::query()->acrossOrganizations()->sole();

    expect($this->gym->refresh()->isSuspended())->toBeFalse()
        ->and($pause->resume_note)->toBe('Paid INV-000001')
        ->and($pause->resumedBy)->toBeNull();
});

it('leaves an admin\'s pause alone when a bill is paid', function () {
    $this->gym->suspend($this->admin, 'Owner asked to close for renovation');

    $this->invoice->recordPayment(Money::of('2,000'), GymPaymentMethod::Cash, null, CarbonImmutable::parse('2026-11-28'), $this->admin);

    expect($this->gym->refresh()->isSuspended())->toBeTrue();
});

it('gives more time, reopening a gym the invoice paused, and pauses it again on the new day', function () {
    onDay('2026-12-12');

    $this->invoice->moveGraceTo(CarbonImmutable::parse('2026-12-20'), $this->admin);

    expect($this->gym->refresh()->isSuspended())->toBeFalse()
        ->and(GymSuspension::query()->acrossOrganizations()->sole()->resume_note)->toBe('Given until 20 Dec 2026 to pay INV-000001');

    onDay('2026-12-19');
    expect($this->gym->refresh()->isSuspended())->toBeFalse();

    onDay('2026-12-20');
    expect($this->gym->refresh()->isSuspendedForUnpaidBill())->toBeTrue();

    expect(fn () => $this->invoice->refresh()->moveGraceTo(CarbonImmutable::parse('2026-12-20'), $this->admin))->toThrow(BillingRefused::class, 'after today');
});

it('doesn\'t pause again for the same invoice after an admin resumed the gym by hand', function () {
    onDay('2026-12-12');
    $this->gym->refresh()->resume($this->admin, 'Owner promised to pay on Monday');

    onDay('2026-12-13');

    expect($this->gym->refresh()->isSuspended())->toBeFalse()
        ->and(app(GymBills::class)->statusOf($this->gym))->toBe(GymBillStatus::Overdue);
});
