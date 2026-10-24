<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 07:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
});

it('lists my visits to the gym, newest first, with the reason when I was turned away', function () {
    $monday = CheckIn::factory()->for($this->tigist)->for($this->mainBranch)->create(['checked_in_at' => now()->subDays(5)]);
    $today = CheckIn::factory()->for($this->tigist)->for($this->mainBranch)->denied(CheckInDenial::MembershipExpired)->create();
    CheckIn::factory()->for(Member::factory()->for($this->mainBranch, 'homeBranch'))->for($this->mainBranch)->create();
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.check-ins.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$today->id, $monday->id])
        ->assertJsonPath('data.0.decision', 'deny')
        ->assertJsonPath('data.0.reason_code', 'MEMBERSHIP_EXPIRED')
        ->assertJsonPath('data.0.branch.name', 'Main Branch')
        ->assertJsonPath('data.1.decision', 'allow')
        ->assertJsonMissingPath('data.0.checked_in_by');
});

it('does not show my visits to another gym', function () {
    $otherGym = Organization::factory()->create(['slug' => 'other-gym']);
    $myMembershipThere = Member::factory()->for(Branch::factory()->for($otherGym), 'homeBranch')->create(['phone' => '0922 34 56 78']);
    CheckIn::factory()->for($myMembershipThere)->create();
    actingAsMember($this->tigist);

    $this->getJson(route('api.v1.me.gyms.check-ins.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
