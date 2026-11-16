<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Events\PaymentLinkSentToMember;
use App\Domains\Billing\Exceptions\PaymentRefused;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

it('refuses a second open link for a membership, but keeps cancelled ones', function () {
    $membership = Membership::factory()->create();
    PaymentLink::factory()->for($membership)->cancelled()->create();
    PaymentLink::factory()->for($membership)->create();

    PaymentLink::factory()->for($membership)->create();
})->throws(QueryException::class, 'violates unique constraint');

it('refuses a zero amount, half a cancellation, or a paid link without its payment', function (array $attributes) {
    PaymentLink::factory()->create($attributes);
})->with([
    'a zero amount' => [['amount' => Money::zero()]],
    'a cancellation without who cancelled it' => [['cancelled_at' => '2026-10-23 10:00:00']],
    'a paid link without its payment' => [['status' => PaymentLinkStatus::Paid]],
])->throws(QueryException::class, 'violates check constraint');

it('refuses a membership of another gym', function () {
    $otherGymsMembership = Membership::factory()->create();

    PaymentLink::factory()->create([
        'organization_id' => Membership::factory()->create()->organization_id,
        'membership_id' => $otherGymsMembership->id,
    ]);
})->throws(QueryException::class, 'violates foreign key constraint');

it('is marked refunded once the gym gives the money back, with who and an optional note', function () {
    $paymentLink = PaymentLink::factory()->create(['status' => PaymentLinkStatus::RefundNeeded, 'settled_at' => now()]);
    actingAsOrganization($paymentLink->organization);
    $accountant = Staff::factory()->for($paymentLink->organization)->create();

    $paymentLink->markRefunded($accountant, '  CHAPA-RF-123 ');

    expect($paymentLink->status)->toBe(PaymentLinkStatus::Refunded)
        ->and($paymentLink->refunded_at)->not->toBeNull()
        ->and($paymentLink->refundedBy->is($accountant))->toBeTrue()
        ->and($paymentLink->refund_note)->toBe('CHAPA-RF-123');
});

it('is marked refunded only when it needs a refund', function (PaymentLinkStatus $status) {
    $paymentLink = PaymentLink::factory()->create(['status' => $status]);
    actingAsOrganization($paymentLink->organization);

    expect($paymentLink->refusalToMarkRefunded()?->getMessage())->toBe('Only a link needing a refund can be marked refunded.')
        ->and(fn () => $paymentLink->markRefunded(Staff::factory()->for($paymentLink->organization)->create(), null))
        ->toThrow(PaymentRefused::class);
})->with([PaymentLinkStatus::Pending]);

it('is texted to the member while open, at most once every 10 minutes', function () {
    Event::fake([PaymentLinkSentToMember::class]);
    $paymentLink = PaymentLink::factory()->create();
    actingAsOrganization($paymentLink->organization);
    $now = CarbonImmutable::parse('2026-12-20 10:00:00');

    $paymentLink->sendToMember($now);

    expect($paymentLink->sms_sent_at->equalTo($now))->toBeTrue()
        ->and($paymentLink->refusalToSendBySms($now->addMinutes(9))?->getMessage())->toContain('a moment ago')
        ->and($paymentLink->refusalToSendBySms($now->addMinutes(11)))->toBeNull();
    Event::assertDispatched(PaymentLinkSentToMember::class, fn (PaymentLinkSentToMember $event): bool => $event->paymentLink->is($paymentLink));

    $paymentLink->cancel(Staff::factory()->for($paymentLink->organization)->create());

    expect($paymentLink->refusalToSendBySms($now->addHour())?->getMessage())->toBe('Only an open link can be texted to the member.');
});

it('refuses a refunded link without the time it was refunded', function () {
    PaymentLink::factory()->create(['status' => PaymentLinkStatus::Refunded, 'settled_at' => now()]);
})->throws(QueryException::class, 'violates check constraint');
