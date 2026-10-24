<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    actingAsMember($this->tigist);
});

function myDashboard(): TestResponse
{
    return test()->getJson(route('api.v1.me.gyms.dashboard', ['organization' => 'demo-gym']));
}

it('shows my membership, what I owe, my last visits and my unread messages in one call', function () {
    $membership = Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-10-06', 'ends_on' => '2026-11-05']);
    Payment::factory()->for($membership)->create(['amount' => Money::of('1,000')]);
    CheckIn::factory()->for($this->tigist)->for($this->mainBranch)->count(6)->sequence(fn ($sequence) => ['checked_in_at' => now()->subDays($sequence->index)])->create();
    CheckIn::factory()->for(Member::factory()->for($this->mainBranch, 'homeBranch'))->for($this->mainBranch)->create();
    $this->tigist->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'membership_expiring', 'data' => ['details' => [], 'data' => []]]);

    myDashboard()
        ->assertOk()
        ->assertJsonPath('data.membership.id', $membership->id)
        ->assertJsonPath('data.membership.plan.name', 'Monthly')
        ->assertJsonPath('data.membership.status', 'active')
        ->assertJsonPath('data.membership.ends_on', '2026-11-05')
        ->assertJsonPath('data.membership.frozen_until', null)
        ->assertJsonPath('data.membership.balance_due.formatted', '1,500.00 ETB')
        ->assertJsonPath('data.next_membership', null)
        ->assertJsonCount(5, 'data.recent_check_ins')
        ->assertJsonPath('data.recent_check_ins.0.branch.name', 'Main Branch')
        ->assertJsonPath('data.balance_due.formatted', '1,500.00 ETB')
        ->assertJsonPath('data.open_payment_link', null)
        ->assertJsonPath('data.unread_notifications', 1);
});

it('says until when my membership is frozen, and shows the one I already bought after it', function () {
    $membership = Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-10-06', 'ends_on' => '2026-11-12']);
    MembershipFreeze::factory()->for($membership)->create(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-26']);
    $next = Membership::factory()->for($this->tigist)->for($this->monthly)->create(['starts_on' => '2026-11-13', 'ends_on' => '2026-12-12']);

    myDashboard()
        ->assertJsonPath('data.membership.status', 'frozen')
        ->assertJsonPath('data.membership.frozen_until', '2026-10-26')
        ->assertJsonPath('data.next_membership.id', $next->id)
        ->assertJsonPath('data.next_membership.status', 'upcoming');
});

it('shows my open payment link', function () {
    $membership = Membership::factory()->for($this->tigist)->for($this->monthly)->create();
    $paymentLink = PaymentLink::factory()->for($membership)->create();

    myDashboard()->assertJsonPath('data.open_payment_link.id', $paymentLink->id);
});

it('shows nothing much when I have no membership yet', function () {
    CheckIn::factory()->for($this->tigist)->for($this->mainBranch)->denied(CheckInDenial::MembershipNone)->create();

    myDashboard()
        ->assertOk()
        ->assertJsonPath('data.membership', null)
        ->assertJsonPath('data.next_membership', null)
        ->assertJsonPath('data.recent_check_ins.0.reason_code', 'MEMBERSHIP_NONE')
        ->assertJsonPath('data.balance_due.formatted', '0.00 ETB')
        ->assertJsonPath('data.unread_notifications', 0);
});
