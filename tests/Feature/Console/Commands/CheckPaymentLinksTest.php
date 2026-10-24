<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));
});

/**
 * A payment link of a new gym that has connected its own Chapa account.
 */
function linkOfAGymWithChapa(?PaymentLinkFactory $paymentLinks = null): PaymentLink
{
    $paymentLink = ($paymentLinks ?? PaymentLink::factory())->create();
    ChapaAccount::factory()->create(['organization_id' => $paymentLink->organization_id]);

    return $paymentLink;
}

/**
 * Where the payment link stands now.
 */
function statusOfPaymentLink(PaymentLink $paymentLink): PaymentLinkStatus
{
    return PaymentLink::query()->acrossOrganizations()->findOrFail($paymentLink->id)->status;
}

it('records the open links that Chapa says were paid, in every gym, each asked with its own key', function () {
    $paidThisWeek = linkOfAGymWithChapa(PaymentLink::factory()->state(['created_at' => CarbonImmutable::now()->subDays(6)]));
    $paidToday = linkOfAGymWithChapa();
    $notPaidYet = linkOfAGymWithChapa();
    fakeChapaPaid($paidThisWeek, $paidToday);

    $this->artisan('billing:check-payment-links')
        ->expectsOutput('Checked 3 open payment links with Chapa.')
        ->assertSuccessful();

    expect(statusOfPaymentLink($paidThisWeek))->toBe(PaymentLinkStatus::Paid)
        ->and(statusOfPaymentLink($paidToday))->toBe(PaymentLinkStatus::Paid)
        ->and(statusOfPaymentLink($notPaidYet))->toBe(PaymentLinkStatus::Pending)
        ->and(Payment::query()->acrossOrganizations()->count())->toBe(2);

    $keyOfPaidTodaysGym = ChapaAccount::query()->acrossOrganizations()
        ->where('organization_id', $paidToday->organization_id)
        ->sole()
        ->secret_key;

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), "/{$paidToday->tx_ref}")
        && $request->hasHeader('Authorization', "Bearer {$keyOfPaidTodaysGym}"));
});

it('leaves out links older than a week, links no longer open, and gyms without Chapa', function () {
    $tooOld = linkOfAGymWithChapa(PaymentLink::factory()->state(['created_at' => CarbonImmutable::now()->subDays(8)]));
    $cancelled = linkOfAGymWithChapa(PaymentLink::factory()->cancelled());
    $ofAGymWithoutChapa = PaymentLink::factory()->create();
    fakeChapaPaid($tooOld, $cancelled, $ofAGymWithoutChapa);

    $this->artisan('billing:check-payment-links')
        ->expectsOutput('Checked 0 open payment links with Chapa.')
        ->assertSuccessful();

    expect(Payment::query()->acrossOrganizations()->exists())->toBeFalse();

    Http::assertNothingSent();
});

it('carries on with the other links when Chapa does not answer about one', function () {
    $unanswered = linkOfAGymWithChapa();
    $paid = linkOfAGymWithChapa();
    Http::fake([
        "api.chapa.co/v1/transaction/verify/{$unanswered->tx_ref}" => Http::response(null, 503),
        "api.chapa.co/v1/transaction/verify/{$paid->tx_ref}" => Http::response(chapaPaidAnswer($paid)),
    ]);

    $this->artisan('billing:check-payment-links')
        ->expectsOutput("Payment link {$unanswered->id}: Chapa didn't answer. Try again in a moment.")
        ->expectsOutput('Checked 1 open payment link with Chapa.')
        ->assertSuccessful();

    expect(statusOfPaymentLink($unanswered))->toBe(PaymentLinkStatus::Pending)
        ->and(statusOfPaymentLink($paid))->toBe(PaymentLinkStatus::Paid);
});

it('runs every ten minutes, never twice at once', function () {
    $event = collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'billing:check-payment-links'));

    expect($event->expression)->toBe('*/10 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
