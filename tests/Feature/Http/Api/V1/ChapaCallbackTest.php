<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->member = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->membership = Membership::factory()->for($this->member)->for($this->monthly)->create();
    $this->chapaAccount = ChapaAccount::factory()->for($this->gym)->create(['secret_key' => 'CHASECK_TEST-demogymkey']);
    $this->paymentLink = PaymentLink::factory()->for($this->membership)->create(['branch_id' => $this->mainBranch->id]);
});

/**
 * Call the callback the way Chapa does once a member has paid on its page.
 */
function callBackAfterPaying(PaymentLink $paymentLink): TestResponse
{
    return test()->getJson(route('api.v1.chapa.callback', [
        'trx_ref' => $paymentLink->tx_ref,
        'ref_id' => 'APfqvRZJ0bEn',
        'status' => 'success',
    ]));
}

it('records the payment once Chapa confirms it, with Chapa\'s reference and no staff member', function () {
    fakeChapaPaid($this->paymentLink);

    callBackAfterPaying($this->paymentLink)
        ->assertOk()
        ->assertJsonPath('message', 'Checked with Chapa.');

    $payment = Payment::query()->acrossOrganizations()->sole();
    $paymentLink = PaymentLink::query()->acrossOrganizations()->sole();

    expect($payment->method)->toBe(PaymentMethod::Chapa)
        ->and($payment->reference)->toBe('APfqvRZJ0bEn')
        ->and($payment->amount)->toEqual(Money::of('2,500'))
        ->and($payment->receipt_number)->toBe(1)
        ->and($payment->paid_on->toDateString())->toBe('2026-10-23')
        ->and($payment->membership_id)->toBe($this->membership->id)
        ->and($payment->branch_id)->toBe($this->mainBranch->id)
        ->and($payment->received_by_staff_id)->toBeNull()
        ->and($paymentLink->status)->toBe(PaymentLinkStatus::Paid)
        ->and($paymentLink->payment_id)->toBe($payment->id)
        ->and($paymentLink->chapa_reference)->toBe('APfqvRZJ0bEn')
        ->and($paymentLink->settled_at?->equalTo(CarbonImmutable::now()))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === "https://api.chapa.co/v1/transaction/verify/{$this->paymentLink->tx_ref}"
        && $request->hasHeader('Authorization', 'Bearer CHASECK_TEST-demogymkey'));
});

it('records one payment however many times Chapa calls back, by POST or GET', function () {
    fakeChapaPaid($this->paymentLink);

    $this->postJson(route('api.v1.chapa.callback'), ['tx_ref' => $this->paymentLink->tx_ref])->assertOk();
    callBackAfterPaying($this->paymentLink)->assertOk();
    callBackAfterPaying($this->paymentLink)->assertOk();

    expect(Payment::query()->acrossOrganizations()->count())->toBe(1);

    Http::assertSentCount(1);
});

it('records nothing unless Chapa says the link was paid in full', function (array $data) {
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => Http::response(chapaPaidAnswer($this->paymentLink, $data))]);

    callBackAfterPaying($this->paymentLink)->assertOk();

    $paymentLink = PaymentLink::query()->acrossOrganizations()->sole();

    expect(Payment::query()->acrossOrganizations()->exists())->toBeFalse()
        ->and($paymentLink->status)->toBe(PaymentLinkStatus::Pending)
        ->and($paymentLink->settled_at)->toBeNull();
})->with([
    'not paid yet' => [['status' => 'pending']],
    'failed' => [['status' => 'failed']],
    'less than the link asks' => [['amount' => '1000.00']],
    'in another currency' => [['currency' => 'USD']],
    'for another transaction' => [['tx_ref' => 'GP-01JSOMETHINGELSE']],
    'without Chapa\'s reference' => [['reference' => null]],
]);

it('leaves the link open while Chapa knows of no payment for it', function (int $status) {
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => Http::response([
        'message' => 'Invalid transaction or Transaction not found',
        'status' => 'failed',
        'data' => null,
    ], $status)]);

    callBackAfterPaying($this->paymentLink)->assertOk();

    expect(Payment::query()->acrossOrganizations()->exists())->toBeFalse()
        ->and(PaymentLink::query()->acrossOrganizations()->sole()->status)->toBe(PaymentLinkStatus::Pending);
})->with([400, 404]);

it('reads the amount whether Chapa sends it as text or as a number', function (string|int|float $amount) {
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => Http::response(chapaPaidAnswer($this->paymentLink, ['amount' => $amount]))]);

    callBackAfterPaying($this->paymentLink)->assertOk();

    expect(PaymentLink::query()->acrossOrganizations()->sole()->status)->toBe(PaymentLinkStatus::Paid);
})->with(['2500.00', '2500', 2500, 2500.0]);

it('marks the link refund needed when the membership was paid another way first', function () {
    Payment::factory()->for($this->membership)->create();
    fakeChapaPaid($this->paymentLink);

    callBackAfterPaying($this->paymentLink)->assertOk();
    callBackAfterPaying($this->paymentLink)->assertOk();

    $paymentLink = PaymentLink::query()->acrossOrganizations()->sole();

    expect(Payment::query()->acrossOrganizations()->count())->toBe(1)
        ->and($paymentLink->status)->toBe(PaymentLinkStatus::RefundNeeded)
        ->and($paymentLink->payment_id)->toBeNull()
        ->and($paymentLink->chapa_reference)->toBe('APfqvRZJ0bEn')
        ->and($paymentLink->settled_at)->not->toBeNull();

    Http::assertSentCount(1);
});

it('still records a payment on a cancelled link while the membership owes it', function () {
    $cancelledLink = PaymentLink::factory()->for($this->membership)->cancelled()->create();
    fakeChapaPaid($cancelledLink);

    callBackAfterPaying($cancelledLink)->assertOk();

    expect(PaymentLink::query()->acrossOrganizations()->findOrFail($cancelledLink->id)->status)->toBe(PaymentLinkStatus::Paid)
        ->and(Payment::query()->acrossOrganizations()->sole()->method)->toBe(PaymentMethod::Chapa);
});

it('asks Chapa with the key of the gym the link belongs to', function () {
    $otherGymsLink = PaymentLink::factory()->create();
    ChapaAccount::factory()->create(['organization_id' => $otherGymsLink->organization_id, 'secret_key' => 'CHASECK_TEST-othergymkey']);
    fakeChapaPaid($otherGymsLink);

    callBackAfterPaying($otherGymsLink)->assertOk();

    $payment = Payment::query()->acrossOrganizations()->sole();

    expect($payment->organization_id)->toBe($otherGymsLink->organization_id)
        ->and($payment->receipt_number)->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer CHASECK_TEST-othergymkey'));
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer CHASECK_TEST-demogymkey'));
});

it('answers 404 for a transaction it does not know, without asking Chapa', function () {
    Http::fake();

    $this->getJson(route('api.v1.chapa.callback', ['trx_ref' => 'GP-01JUNKNOWN']))->assertNotFound();
    $this->getJson(route('api.v1.chapa.callback'))->assertNotFound();
    $this->getJson(route('api.v1.chapa.callback', ['trx_ref' => [$this->paymentLink->tx_ref]]))->assertNotFound();

    Http::assertNothingSent();
});

it('records nothing for a gym that has disconnected Chapa', function () {
    $this->chapaAccount->delete();
    Http::fake();

    callBackAfterPaying($this->paymentLink)->assertOk();

    expect(Payment::query()->acrossOrganizations()->exists())->toBeFalse();

    Http::assertNothingSent();
});
