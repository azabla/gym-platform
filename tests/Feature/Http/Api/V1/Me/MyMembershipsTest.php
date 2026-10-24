<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->october = Membership::factory()->for($this->tigist)->for($this->monthly)->create([
        'starts_on' => '2026-10-01',
        'ends_on' => '2026-11-03',
        'freeze_days_allowed' => 7,
    ]);
});

it('lists my memberships at the gym, newest first, with what is paid and due, and the freeze days left', function () {
    $november = Membership::factory()->for($this->tigist)->for($this->monthly)->create([
        'starts_on' => '2026-11-04',
        'ends_on' => '2026-12-03',
        'price' => Money::of('2,250'),
        'discount_reason' => 'Student',
    ]);
    MembershipFreeze::factory()->for($this->october)->create(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
    Payment::factory()->for($this->october)->create(['amount' => Money::of('1,500')]);
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.memberships.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$november->id, $this->october->id])
        ->assertJsonPath('data.0.status', 'upcoming')
        ->assertJsonPath('data.0.price.amount_minor', 225000)
        ->assertJsonPath('data.1.status', 'active')
        ->assertJsonPath('data.1.plan.name', 'Monthly')
        ->assertJsonPath('data.1.amount_paid.amount_minor', 150000)
        ->assertJsonPath('data.1.balance_due.amount_minor', 100000)
        ->assertJsonPath('data.1.freeze_days_allowed', 7)
        ->assertJsonPath('data.1.freeze_days_used', 3)
        ->assertJsonPath('data.1.freeze_days_left', 4)
        ->assertJsonPath('data.1.freezes', [['starts_on' => '2026-10-10', 'ends_on' => '2026-10-12', 'days' => 3]])
        ->assertJsonMissingPath('data.0.discount')
        ->assertJsonMissingPath('data.0.list_price')
        ->assertJsonMissingPath('data.1.sold_by');
});

it('shows one of my memberships, but not another member\'s', function () {
    $abebesMembership = Membership::factory()
        ->for(Member::factory()->for($this->mainBranch, 'homeBranch'))
        ->for($this->monthly)
        ->create();
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->october]))
        ->assertOk()
        ->assertJsonPath('data.id', $this->october->id)
        ->assertJsonPath('data.balance_due.amount_minor', 250000);

    $this->getJson(route('api.v1.me.gyms.memberships.show', ['organization' => 'demo-gym', 'membership' => $abebesMembership]))
        ->assertNotFound();
});

it('answers 404 for a gym where I am not a member, does not exist, or archived me', function () {
    Organization::factory()->create(['slug' => 'bole-fitness']);
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.memberships.index', ['organization' => 'bole-fitness']))->assertNotFound();
    $this->getJson(route('api.v1.me.gyms.memberships.index', ['organization' => 'no-such-gym']))->assertNotFound();

    $this->tigist->delete();

    $this->getJson(route('api.v1.me.gyms.memberships.index', ['organization' => 'demo-gym']))->assertNotFound();
});

it('answers 404 to a user without a phone number, such as staff', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.me.gyms.memberships.index', ['organization' => 'demo-gym']))->assertNotFound();
});
