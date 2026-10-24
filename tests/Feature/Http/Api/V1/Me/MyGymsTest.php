<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));
});

it('lists every gym where my phone number is registered, with my membership and what I owe there', function () {
    $demoGym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $mainBranch = Branch::factory()->for($demoGym)->create(['name' => 'Main Branch']);
    $tigist = Member::factory()->for($mainBranch, 'homeBranch')->create(['full_name' => 'Tigist Alemu', 'phone' => '0922 34 56 78']);
    $membership = Membership::factory()
        ->for($tigist)
        ->for(Plan::factory()->for($demoGym)->state(['name' => 'Monthly', 'price' => Money::of('2,500')]))
        ->create();
    Payment::factory()->for($membership)->create(['amount' => Money::of('1,500')]);

    $boleFitness = Organization::factory()->create(['slug' => 'bole-fitness', 'name' => 'Bole Fitness']);
    Member::factory()->for(Branch::factory()->for($boleFitness), 'homeBranch')->create(['phone' => '+251922345678']);
    Member::factory()->create(['phone' => '0922 34 56 78'])->delete();
    Member::factory()->create(['phone' => '0911 11 11 11']);
    actingAsMember($tigist);

    $this->getJson(route('api.v1.me.gyms.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.slug', 'demo-gym')
        ->assertJsonPath('data.0.name', 'Demo Gym')
        ->assertJsonPath('data.0.member.id', $tigist->id)
        ->assertJsonPath('data.0.member.full_name', 'Tigist Alemu')
        ->assertJsonPath('data.0.member.home_branch.name', 'Main Branch')
        ->assertJsonPath('data.0.membership.plan.name', 'Monthly')
        ->assertJsonPath('data.0.membership.status', 'active')
        ->assertJsonPath('data.0.balance_due.amount_minor', 100000)
        ->assertJsonPath('data.1.slug', 'bole-fitness')
        ->assertJsonPath('data.1.membership', null)
        ->assertJsonPath('data.1.balance_due.amount_minor', 0);
});

it('shows no gyms to a user without a phone number, such as staff', function () {
    Member::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.me.gyms.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('needs a signed-in user', function () {
    $this->getJson(route('api.v1.me.gyms.index'))->assertUnauthorized();
});
