<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Events\PaymentRecorded;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;

it('keeps money brought in apart from money taken at the desk', function () {
    expect(PaymentMethod::BroughtIn->hasReference())->toBeFalse()
        ->and(PaymentMethod::forTheDesk())->toBe([PaymentMethod::Cash, PaymentMethod::Telebirr, PaymentMethod::CbeBirr, PaymentMethod::BankTransfer])
        ->and(PaymentMethod::moneyTaken())->not->toContain(PaymentMethod::BroughtIn)
        ->and(PaymentMethod::moneyTaken())->toContain(PaymentMethod::Chapa);
});

it('stores money brought in without a transaction number, and tells the member nothing', function () {
    $gym = actingAsOrganization(Organization::factory()->create());
    $member = Member::factory()->for(Branch::factory()->for($gym), 'homeBranch')->create();
    $payment = Payment::factory()->for(Membership::factory()->for($member))->broughtIn()->create();

    event(new PaymentRecorded($payment));

    expect($payment->refresh()->method)->toBe(PaymentMethod::BroughtIn)
        ->and($payment->reference)->toBeNull()
        ->and($member->notifications()->count())->toBe(0);
});
