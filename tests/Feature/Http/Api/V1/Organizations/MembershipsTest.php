<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 09:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->member = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create();
    $this->monthly = Plan::factory()->for($this->gym)->create([
        'name' => 'Monthly',
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
});

/**
 * POST a sale of the given details to the member's memberships.
 *
 * @param  array<string, mixed>  $details
 */
function sellMembership(Member $member, array $details): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.members.memberships.store', ['organization' => 'demo-gym', 'member' => $member]),
        $details,
    );
}

it('sells a plan to a member, starting today', function () {
    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist);

    sellMembership($this->member, ['plan_id' => $this->monthly->id])
        ->assertCreated()
        ->assertJsonPath('data.plan.name', 'Monthly')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.starts_on', '2026-10-23')
        ->assertJsonPath('data.ends_on', '2026-11-22')
        ->assertJsonPath('data.price.amount_minor', 250000)
        ->assertJsonPath('data.sold_by.id', $receptionist->id)
        ->assertJsonPath('data.cancellation', null);
});

it('starts a new membership the day after the current one ends', function () {
    Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sellMembership($this->member, ['plan_id' => $this->monthly->id])
        ->assertCreated()
        ->assertJsonPath('data.status', 'upcoming')
        ->assertJsonPath('data.starts_on', '2026-11-01')
        ->assertJsonPath('data.ends_on', '2026-11-30');
});

it('lets only owners and managers start a membership on a past date', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sellMembership($this->member, ['plan_id' => $this->monthly->id, 'starts_on' => '2026-10-01'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on']);

    actingAsStaff($this->gym, StaffRole::Manager);

    sellMembership($this->member, ['plan_id' => $this->monthly->id, 'starts_on' => '2026-10-01'])
        ->assertCreated()
        ->assertJsonPath('data.starts_on', '2026-10-01')
        ->assertJsonPath('data.ends_on', '2026-10-31');
});

it('refuses dates that overlap another membership of the member', function () {
    Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sellMembership($this->member, ['plan_id' => $this->monthly->id, 'starts_on' => '2026-10-25'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on' => 'The first free start date is 1 November 2026.']);
});

it('refuses an archived plan or a plan of another gym', function () {
    $archivedPlan = Plan::factory()->for($this->gym)->create();
    $archivedPlan->delete();
    $otherGymsPlan = Plan::factory()->create();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sellMembership($this->member, ['plan_id' => $archivedPlan->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan_id']);

    sellMembership($this->member, ['plan_id' => $otherGymsPlan->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan_id']);
});

it('does not let trainers sell memberships', function () {
    actingAsStaff($this->gym, StaffRole::Trainer);

    sellMembership($this->member, ['plan_id' => $this->monthly->id])->assertForbidden();
});

it('cannot sell to a member of another gym', function () {
    $otherGymsMember = Member::factory()->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    sellMembership($otherGymsMember, ['plan_id' => $this->monthly->id])->assertNotFound();
});

it('lists a member\'s memberships, newest first', function () {
    Membership::factory()->for($this->member)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
    Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(route('api.v1.organizations.members.memberships.index', ['organization' => 'demo-gym', 'member' => $this->member]))
        ->assertOk()
        ->assertJsonPath('data.*.starts_on', ['2026-10-01', '2026-09-01'])
        ->assertJsonPath('data.*.status', ['active', 'expired']);
});

it('shows a membership', function () {
    $membership = Membership::factory()->for($this->member)->for($this->monthly)->create();
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(route('api.v1.organizations.memberships.show', ['organization' => 'demo-gym', 'membership' => $membership]))
        ->assertOk()
        ->assertJsonPath('data.member_id', $this->member->id)
        ->assertJsonPath('data.plan.name', 'Monthly');
});

it('lets a manager cancel a membership, with a reason', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    $manager = actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(route('api.v1.organizations.memberships.cancellation.store', ['organization' => 'demo-gym', 'membership' => $membership]), [
        'reason' => 'Sold the wrong plan.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation.cancelled_by.id', $manager->id)
        ->assertJsonPath('data.cancellation.reason', 'Sold the wrong plan.');
});

it('does not let receptionists cancel memberships', function () {
    $membership = Membership::factory()->for($this->member)->create();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.memberships.cancellation.store', ['organization' => 'demo-gym', 'membership' => $membership]), [
        'reason' => 'Sold the wrong plan.',
    ])->assertForbidden();
});

it('refuses to cancel a membership that has ended or is already cancelled', function () {
    $expired = Membership::factory()->for($this->member)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
    $cancelled = Membership::factory()->for($this->member)->cancelled()->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    actingAsStaff($this->gym, StaffRole::Owner);

    foreach ([$expired, $cancelled] as $membership) {
        $this->postJson(route('api.v1.organizations.memberships.cancellation.store', ['organization' => 'demo-gym', 'membership' => $membership]), [
            'reason' => 'Sold the wrong plan.',
        ])->assertUnprocessable()->assertJsonValidationErrors(['membership']);
    }
});

it('frees the days of a cancelled membership for a new one', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(route('api.v1.organizations.memberships.cancellation.store', ['organization' => 'demo-gym', 'membership' => $membership]), [
        'reason' => 'Sold the wrong plan.',
    ])->assertOk();

    sellMembership($this->member, ['plan_id' => $this->monthly->id])
        ->assertCreated()
        ->assertJsonPath('data.starts_on', '2026-10-23');
});
