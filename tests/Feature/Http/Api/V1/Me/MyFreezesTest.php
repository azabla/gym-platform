<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', 'Africa/Addis_Ababa'));
    $this->pushes = fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $branch = Branch::factory()->for($this->gym)->create();
    $this->tigist = Member::factory()->for($branch, 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->membership = Membership::factory()->for($this->tigist)->create([
        'starts_on' => '2026-11-01',
        'ends_on' => '2026-11-30',
        'freeze_days_allowed' => 7,
    ]);
});

function myFreezes(Membership $membership): string
{
    return route('api.v1.me.gyms.memberships.freezes.store', ['organization' => 'demo-gym', 'membership' => $membership->id]);
}

it('lets a member freeze their membership from today, moving its end date later', function () {
    actingAsMember($this->tigist);

    $this->postJson(myFreezes($this->membership), ['ends_on' => '2026-11-17', 'reason' => 'Travelling to Bahir Dar.'])
        ->assertCreated()
        ->assertJsonPath('data.ends_on', '2026-12-05')
        ->assertJsonPath('data.freeze_days_left', 2)
        ->assertJsonPath('data.freezes.0.starts_on', '2026-11-13')
        ->assertJsonPath('data.freezes.0.frozen_in_app', true);

    $freeze = MembershipFreeze::query()->acrossOrganizations()->sole();

    expect($freeze->isFromTheApp())->toBeTrue()
        ->and($freeze->reason)->toBe('Travelling to Bahir Dar.')
        ->and($this->tigist->notifications()->count())->toBe(0)
        ->and($this->pushes->sent)->toBe([]);
});

it('refuses a freeze that starts in the past or goes beyond the freeze days left', function (array $dates, string $field, string $message) {
    actingAsMember($this->tigist);

    $this->postJson(myFreezes($this->membership), $dates)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);

    expect(MembershipFreeze::query()->acrossOrganizations()->count())->toBe(0);
})->with([
    'starting yesterday' => [['starts_on' => '2026-11-12', 'ends_on' => '2026-11-14'], 'starts_on', 'A freeze can start today at the earliest.'],
    'longer than the days left' => [['ends_on' => '2026-11-20'], 'ends_on', 'This membership can be frozen for 7 more days.'],
]);

it('refuses a plan without freeze days', function () {
    $this->membership->forceFill(['freeze_days_allowed' => 0])->save();
    actingAsMember($this->tigist);

    $this->postJson(myFreezes($this->membership), ['ends_on' => '2026-11-14'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_on' => 'This membership has no freeze days left.']);
});

it('lets a member end a freeze early, giving the days back', function () {
    actingAsMember($this->tigist);
    $this->postJson(myFreezes($this->membership), ['starts_on' => '2026-11-15', 'ends_on' => '2026-11-18'])->assertCreated();
    $freeze = MembershipFreeze::query()->acrossOrganizations()->sole();

    $this->postJson(route('api.v1.me.gyms.memberships.freezes.end', ['organization' => 'demo-gym', 'membership' => $this->membership->id, 'freeze' => $freeze->id]))
        ->assertOk()
        ->assertJsonPath('data.ends_on', '2026-11-30')
        ->assertJsonPath('data.freeze_days_left', 7)
        ->assertJsonPath('data.freezes', []);
});

it('hides another member\'s membership', function () {
    $someoneElses = Membership::factory()->for(Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch'))->create();
    actingAsMember($this->tigist);

    $this->postJson(myFreezes($someoneElses), ['ends_on' => '2026-11-14'])->assertNotFound();
});
