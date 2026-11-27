<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Insights\GymInsights;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Tuesday 24 November 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly']);
    $this->yearly = Plan::factory()->for($this->gym)->create(['name' => 'Yearly']);
    actingAsOrganization($this->gym);
    $this->insights = app(GymInsights::class);
});

/**
 * A membership of a member of the branch, from one day to another.
 */
function membershipAt(Branch $branch, Plan $plan, string $startsOn, string $endsOn, ?Member $member = null): Membership
{
    return Membership::factory()
        ->for($member ?? Member::factory()->for($branch, 'homeBranch')->create())
        ->for($plan)
        ->create(['starts_on' => $startsOn, 'ends_on' => $endsOn]);
}

it('gives the months covered, ending with the gym\'s current month', function () {
    expect(array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), $this->insights->months($this->gym, 3)))
        ->toBe(['2026-09', '2026-10', '2026-11']);
});

it('adds up revenue by month, plan and method, net of refunds and without voided payments', function () {
    $monthly = membershipAt($this->main, $this->monthly, '2026-10-01', '2026-10-31');
    $yearly = membershipAt($this->main, $this->yearly, '2026-11-01', '2027-10-31');
    Payment::factory()->for($monthly)->create(['amount' => Money::of('2,500'), 'paid_on' => '2026-10-01', 'branch_id' => $this->main->id]);
    Payment::factory()->for($yearly)->create(['amount' => Money::of('20,000'), 'method' => PaymentMethod::Telebirr, 'reference' => 'TB-1', 'paid_on' => '2026-11-02', 'branch_id' => $this->main->id]);
    Payment::factory()->for($yearly)->refund()->create(['amount' => Money::of('1,000'), 'method' => PaymentMethod::Telebirr, 'reference' => 'TB-2', 'paid_on' => '2026-11-20', 'branch_id' => $this->main->id]);
    Payment::factory()->for($monthly)->voided()->create(['amount' => Money::of('500'), 'paid_on' => '2026-11-03', 'branch_id' => $this->main->id]);
    Payment::factory()->for($monthly)->create(['amount' => Money::of('300'), 'paid_on' => '2026-08-31', 'branch_id' => $this->main->id]);

    $revenue = collect($this->insights->revenue($this->gym, 3, null))->keyBy('month');

    expect($revenue->keys()->all())->toBe(['2026-09', '2026-10', '2026-11'])
        ->and($revenue['2026-09']['net']->format())->toBe('0.00 ETB')
        ->and($revenue['2026-10']['net']->format())->toBe('2,500.00 ETB')
        ->and($revenue['2026-10']['by_plan']['Monthly']->format())->toBe('2,500.00 ETB')
        ->and($revenue['2026-11']['net']->format())->toBe('19,000.00 ETB')
        ->and($revenue['2026-11']['by_plan']['Yearly']->format())->toBe('19,000.00 ETB')
        ->and($revenue['2026-11']['by_method']['telebirr']->format())->toBe('19,000.00 ETB')
        ->and($revenue['2026-11']['by_method']['cash']->format())->toBe('0.00 ETB');
});

it('counts revenue only at the branches given', function () {
    $membership = membershipAt($this->main, $this->monthly, '2026-11-01', '2026-11-30');
    Payment::factory()->for($membership)->create(['amount' => Money::of('1,000'), 'paid_on' => '2026-11-01', 'branch_id' => $this->main->id]);
    Payment::factory()->for($membership)->create(['amount' => Money::of('400'), 'paid_on' => '2026-11-02', 'branch_id' => $this->bole->id]);

    expect(collect($this->insights->revenue($this->gym, 3, [$this->bole->id]))->last()['net']->format())->toBe('400.00 ETB');
});

it('sorts memberships that ended into renewed, lapsed and still waiting', function () {
    // Renewed: the next starts the day after.
    $renewer = Member::factory()->for($this->main, 'homeBranch')->create();
    membershipAt($this->main, $this->monthly, '2026-09-01', '2026-09-30', $renewer);
    membershipAt($this->main, $this->monthly, '2026-10-01', '2026-10-31', $renewer);
    // Renewed late, after 14 days: lapsed.
    $late = Member::factory()->for($this->main, 'homeBranch')->create();
    membershipAt($this->main, $this->yearly, '2025-10-01', '2026-09-30', $late);
    membershipAt($this->main, $this->monthly, '2026-10-20', '2026-11-19', $late);
    // Ended 10 days ago, nothing yet: waiting.
    membershipAt($this->main, $this->monthly, '2026-10-15', '2026-11-14');
    // At another branch.
    membershipAt($this->bole, $this->monthly, '2026-09-01', '2026-09-30');

    $renewals = collect($this->insights->renewals($this->gym, 3, [$this->main->id]))->keyBy('month');

    expect($renewals['2026-09'])->toMatchArray(['ended' => 2, 'renewed' => 1, 'lapsed' => 1, 'waiting' => 0, 'rate' => 0.5])
        ->and($renewals['2026-09']['by_plan'])->toBe(['Monthly' => ['renewed' => 1, 'lapsed' => 0, 'waiting' => 0], 'Yearly' => ['renewed' => 0, 'lapsed' => 1, 'waiting' => 0]])
        ->and($renewals['2026-10'])->toMatchArray(['ended' => 1, 'renewed' => 0, 'lapsed' => 1, 'rate' => 0.0])
        ->and($renewals['2026-11'])->toMatchArray(['ended' => 2, 'renewed' => 0, 'lapsed' => 0, 'waiting' => 2, 'rate' => null]);
});

it('leaves out cancelled memberships and gives no rate when nothing is decided yet', function () {
    Membership::factory()->for(Member::factory()->for($this->main, 'homeBranch'))->for($this->monthly)->cancelled()->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);

    expect(collect($this->insights->renewals($this->gym, 3, null))->first())->toMatchArray(['ended' => 0, 'rate' => null]);
});
