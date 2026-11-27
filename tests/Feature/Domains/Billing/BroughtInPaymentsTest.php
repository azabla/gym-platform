<?php

declare(strict_types=1);

use App\Dashboards\GymFigures;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Events\PaymentRecorded;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Insights\GymInsights;
use App\Reports\PaymentsReport;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

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

it('counts money brought in towards what the member owes, but never as money taken', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00', 'Africa/Addis_Ababa'));
    $gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $branch = Branch::factory()->for($gym)->create();
    $member = Member::factory()->for($branch, 'homeBranch')->create();
    $membership = Membership::factory()->for($member)->create(['price' => Money::of('2,500'), 'list_price' => Money::of('2,500'), 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    Payment::factory()->for($membership)->broughtIn()->create(['amount' => Money::of('1,500'), 'paid_on' => '2026-11-01', 'branch_id' => $branch->id]);
    Payment::factory()->for($membership)->create(['amount' => Money::of('500'), 'paid_on' => '2026-11-20', 'branch_id' => $branch->id]);
    $november = Payment::query()->where('paid_on', '>=', '2026-11-01');

    $totals = Payment::totalsOf($november);
    $revenue = app(GymInsights::class)->revenue($gym, 3, null);
    $report = iterator_to_array((new PaymentsReport(new ReportPeriod($gym, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))))->rows(), false);
    $onlyBroughtIn = iterator_to_array((new PaymentsReport(new ReportPeriod($gym, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')), PaymentMethod::BroughtIn))->rows(), false);

    expect(Payment::balanceOf($membership)->balanceDue->format())->toBe('500.00 ETB')
        ->and($totals['net']->format())->toBe('500.00 ETB')
        ->and($totals['brought_in']->format())->toBe('1,500.00 ETB')
        ->and(array_keys($totals['by_method']))->not->toContain('brought_in')
        ->and(end($revenue)['net']->format())->toBe('500.00 ETB')
        ->and(app(GymFigures::class)->of([$gym])[$gym->id]['taken_this_month']->format())->toBe('500.00 ETB')
        ->and($report)->toHaveCount(1)
        ->and($onlyBroughtIn)->toHaveCount(1);
});
