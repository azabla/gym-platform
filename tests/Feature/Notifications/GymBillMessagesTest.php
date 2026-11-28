<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Push\PushChannel;
use App\Domains\Notifications\Sms\SmsChannel;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\PlatformBilling\Enums\GymPaymentMethod;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Domains\Shared\Money\Money;
use App\Notifications\GymBillDueToday;
use App\Notifications\GymBillIssued;
use App\Notifications\GymBillOverdue;
use App\Notifications\GymBillPaid;
use App\Notifications\GymPausedForUnpaidBill;
use App\Notifications\GymPausesTomorrow;
use App\Notifications\MessageText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    $this->manager = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Manager]);
    GymSubscription::start($this->gym, new SubscriptionTerms(PlatformPlan::factory()->create()), firstBillingDay: CarbonImmutable::parse('2026-12-05'));
});

function billingRunOn(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Africa/Addis_Ababa'));
    test()->artisan('platform-billing:run')->assertSuccessful();
}

it('tells only the owners of a new invoice, by inbox, push and SMS, whatever they chose', function () {
    $this->owner->chooseNotifications(['platform_bill' => ['push' => false]]);

    billingRunOn('2026-11-28 10:00');

    Notification::assertSentTo($this->owner, GymBillIssued::class, function (GymBillIssued $message, array $channels): bool {
        return $channels === ['database', PushChannel::class, SmsChannel::class]
            && $message->toArray($this->owner)['details']['invoice'] === 'INV-000001';
    });
    Notification::assertNotSentTo($this->manager, GymBillIssued::class);
});

it('waits for the morning to tell owners of an invoice issued at night', function () {
    billingRunOn('2026-11-28 00:20');

    Notification::assertSentTo($this->owner, GymBillIssued::class, fn (GymBillIssued $message): bool => $message->delay?->equalTo(CarbonImmutable::parse('2026-11-28 07:00', 'Africa/Addis_Ababa')) === true);
});

it('reminds owners on the due day, a week later and the day before the pause, once each', function () {
    billingRunOn('2026-11-28 10:00');

    foreach (['2026-12-04', '2026-12-05', '2026-12-05', '2026-12-12', '2026-12-18', '2026-12-18'] as $day) {
        billingRunOn($day.' 10:00');
    }

    Notification::assertSentToTimes($this->owner, GymBillDueToday::class, 1);
    Notification::assertSentToTimes($this->owner, GymBillOverdue::class, 1);
    Notification::assertSentToTimes($this->owner, GymPausesTomorrow::class, 1);

    billingRunOn('2026-12-19 10:00');

    Notification::assertSentTo($this->owner, GymPausedForUnpaidBill::class);
});

it('tells owners once paid, and that the gym is open again when the bill paused it', function () {
    billingRunOn('2026-11-28 10:00');
    billingRunOn('2026-12-19 10:00');

    $invoice = GymInvoice::query()->acrossOrganizations()->sole();
    $invoice->recordPayment(Money::of('2,000'), GymPaymentMethod::Cash, null, CarbonImmutable::parse('2026-12-19'), User::factory()->create());

    Notification::assertSentTo($this->owner, GymBillPaid::class, fn (GymBillPaid $message): bool => $message->databaseType($this->owner) === 'staff_bill_paid_reopened');
});

it('sends bill messages even while the gym is paused', function () {
    billingRunOn('2026-11-28 10:00');
    $this->gym->suspend(User::factory()->create(), 'Renovation');

    expect(GymBillPaid::for(GymInvoice::query()->acrossOrganizations()->sole(), false)->shouldSend($this->owner, 'sms'))->toBeTrue();
});

it('writes the messages in English and Amharic, with the dates in each', function () {
    $details = ['invoice' => 'INV-000001', 'amount' => '2,000.00 ETB', 'starts_on' => '2026-12-05', 'ends_on' => '2027-01-04', 'due_on' => '2026-12-05', 'pauses_on' => '2026-12-19', 'gym' => 'Demo Gym'];

    expect(MessageText::forStaff('staff_bill_issued', 'sms', $details))
        ->toBe('Demo Gym: platform invoice INV-000001 for 2,000.00 ETB is due on 5 December 2026. Unpaid by 19 December 2026, the gym is paused. Pay with INV-000001 as the reason.')
        ->and(MessageText::forStaff('staff_bill_paused', 'body', $details, 'am'))->toContain('INV-000001')->not->toContain(':amount');
});
