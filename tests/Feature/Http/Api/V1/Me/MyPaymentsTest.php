<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'name' => 'Demo Gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->membership = Membership::factory()->for($this->tigist)->for($this->monthly)->create();
    $this->abebesMembership = Membership::factory()
        ->for(Member::factory()->for($this->mainBranch, 'homeBranch'))
        ->for($this->monthly)
        ->create();
});

it('lists my payments and refunds at the gym, newest first, saying which were voided but not why', function () {
    $cash = Payment::factory()->for($this->membership)->create([
        'amount' => Money::of('1,000'),
        'paid_on' => '2026-10-21',
        'branch_id' => $this->mainBranch->id,
    ]);
    $voided = Payment::factory()->for($this->membership)->byTelebirr('CJ12ABC34D')->voided()->create([
        'amount' => Money::of('500'),
        'paid_on' => '2026-10-22',
    ]);
    Payment::factory()->for($this->abebesMembership)->create();
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.payments.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$voided->id, $cash->id])
        ->assertJsonPath('data.0.method', 'telebirr')
        ->assertJsonPath('data.0.reference', 'CJ12ABC34D')
        ->assertJsonPath('data.0.voided', true)
        ->assertJsonPath('data.1.voided', false)
        ->assertJsonPath('data.1.receipt_number', $cash->receipt_number)
        ->assertJsonPath('data.1.type', 'payment')
        ->assertJsonPath('data.1.branch.name', 'Main Branch')
        ->assertJsonPath('data.1.membership.plan.name', 'Monthly')
        ->assertJsonMissingPath('data.0.void')
        ->assertJsonMissingPath('data.0.received_by');
});

it('shows one of my receipts, but not another member\'s', function () {
    $mine = Payment::factory()->for($this->membership)->create();
    $abebes = Payment::factory()->for($this->abebesMembership)->create();
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.payments.show', ['organization' => 'demo-gym', 'payment' => $mine]))
        ->assertOk()
        ->assertJsonPath('data.amount.amount_minor', 250000);

    $this->getJson(route('api.v1.me.gyms.payments.show', ['organization' => 'demo-gym', 'payment' => $abebes]))
        ->assertNotFound();
});
