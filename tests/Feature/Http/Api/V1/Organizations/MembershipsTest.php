<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
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
        'freeze_days_allowed' => 7,
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

/**
 * POST a freeze of the given details for the membership.
 *
 * @param  array<string, mixed>  $details
 */
function freezeMembership(Membership $membership, array $details): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.memberships.freezes.store', ['organization' => 'demo-gym', 'membership' => $membership]),
        $details,
    );
}

/**
 * POST the early end of the membership's freeze.
 */
function endMembershipFreeze(Membership $membership, MembershipFreeze $freeze): TestResponse
{
    return test()->postJson(route('api.v1.organizations.memberships.freezes.end', [
        'organization' => 'demo-gym',
        'membership' => $membership,
        'freeze' => $freeze,
    ]));
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
        ->assertJsonPath('data.list_price.amount_minor', 250000)
        ->assertJsonPath('data.discount', null)
        ->assertJsonPath('data.freeze_days_allowed', 7)
        ->assertJsonPath('data.sold_by.id', $receptionist->id)
        ->assertJsonPath('data.cancellation', null);
});

it('lets a manager sell at a discount, as an amount or a percentage', function (string $discount, int $pricePaid, int $amountOff) {
    actingAsStaff($this->gym, StaffRole::Manager);

    sellMembership($this->member, ['plan_id' => $this->monthly->id, 'discount' => $discount, 'discount_reason' => 'Student'])
        ->assertCreated()
        ->assertJsonPath('data.list_price.amount_minor', 250000)
        ->assertJsonPath('data.price.amount_minor', $pricePaid)
        ->assertJsonPath('data.discount.amount.amount_minor', $amountOff)
        ->assertJsonPath('data.discount.reason', 'Student');
})->with([
    'ten percent' => ['10%', 225000, 25000],
    '500 birr' => ['500', 200000, 50000],
]);

it('lets only owners and managers give a discount', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sellMembership($this->member, ['plan_id' => $this->monthly->id, 'discount' => '10%', 'discount_reason' => 'Student'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount' => 'Only owners and managers can give a discount.']);
});

it('refuses a discount larger than the price, or without a reason', function (array $details, string $field) {
    actingAsStaff($this->gym, StaffRole::Owner);

    sellMembership($this->member, ['plan_id' => $this->monthly->id, ...$details])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'larger than the price' => [['discount' => '3,000', 'discount_reason' => 'Student'], 'discount'],
    'without a reason' => [['discount' => '10%'], 'discount_reason'],
]);

it('sells a branch-only plan as a membership valid at those branches', function () {
    $boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->monthly->makeAvailableOnlyAt($boleBranch);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    sellMembership($this->member, ['plan_id' => $this->monthly->id])
        ->assertCreated()
        ->assertJsonPath('data.valid_at_all_branches', false)
        ->assertJsonPath('data.branches.*.name', ['Bole Branch']);
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

it('lets a receptionist freeze a membership within its freeze days', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 7]);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    freezeMembership($membership, ['ends_on' => '2026-10-29', 'reason' => 'Travelling to Bahir Dar.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'frozen')
        ->assertJsonPath('data.ends_on', '2026-11-07')
        ->assertJsonPath('data.freeze_days_used', 7)
        ->assertJsonPath('data.freezes.0.starts_on', '2026-10-23')
        ->assertJsonPath('data.freezes.0.days', 7);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))
        ->assertJsonPath('data.0.membership.status', 'frozen');
});

it('lets only owners and managers freeze for more days than the plan allows', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 7]);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    freezeMembership($membership, ['ends_on' => '2026-10-30', 'reason' => 'Travelling.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_on' => 'This membership can be frozen for 7 more days.']);

    actingAsStaff($this->gym, StaffRole::Manager);

    freezeMembership($membership, ['ends_on' => '2026-10-30', 'reason' => 'Travelling.'])
        ->assertCreated()
        ->assertJsonPath('data.freeze_days_used', 8);
});

it('does not let trainers freeze memberships', function () {
    $membership = Membership::factory()->for($this->member)->create(['freeze_days_allowed' => 7]);
    actingAsStaff($this->gym, StaffRole::Trainer);

    freezeMembership($membership, ['ends_on' => '2026-10-25', 'reason' => 'Travelling.'])->assertForbidden();
});

it('refuses a freeze that overlaps another or starts outside the membership', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 30]);
    actingAsOrganization($this->gym);
    $membership->freeze(CarbonImmutable::parse('2026-10-25'), CarbonImmutable::parse('2026-10-27'), 'Ill.', Staff::factory()->for($this->gym)->create());
    actingAsStaff($this->gym, StaffRole::Owner);

    freezeMembership($membership, ['starts_on' => '2026-10-26', 'ends_on' => '2026-10-28', 'reason' => 'Travelling.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on' => 'This membership is already frozen from 25 October 2026 to 27 October 2026.']);

    freezeMembership($membership, ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'reason' => 'Travelling.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on' => 'A freeze must start while the membership runs, from 1 October 2026 to 3 November 2026.']);
});

it('refuses to freeze a cancelled membership', function () {
    $membership = Membership::factory()->for($this->member)->cancelled()->create(['freeze_days_allowed' => 7]);
    actingAsStaff($this->gym, StaffRole::Owner);

    freezeMembership($membership, ['ends_on' => '2026-10-25', 'reason' => 'Travelling.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['membership']);
});

it('lets only owners and managers start a freeze on a past date', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 7]);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    freezeMembership($membership, ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-22', 'reason' => 'Was ill.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_on' => 'Only owners and managers can start a freeze on a past date.']);

    actingAsStaff($this->gym, StaffRole::Manager);

    freezeMembership($membership, ['starts_on' => '2026-10-20', 'ends_on' => '2026-10-22', 'reason' => 'Was ill.'])
        ->assertCreated()
        ->assertJsonPath('data.ends_on', '2026-11-03');
});

it('moves a membership queued after the frozen one', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 7]);
    Membership::factory()->for($this->member)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    freezeMembership($membership, ['ends_on' => '2026-10-29', 'reason' => 'Travelling.'])->assertCreated();

    $this->getJson(route('api.v1.organizations.members.memberships.index', ['organization' => 'demo-gym', 'member' => $this->member]))
        ->assertJsonPath('data.*.starts_on', ['2026-11-08', '2026-10-01'])
        ->assertJsonPath('data.*.ends_on', ['2026-12-07', '2026-11-07']);
});

it('ends a freeze early, taking the unused days off the end date', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 30]);
    actingAsOrganization($this->gym);
    $freeze = $membership->freeze(CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-29'), 'Travelling.', Staff::factory()->for($this->gym)->create());
    actingAsStaff($this->gym, StaffRole::Receptionist);

    endMembershipFreeze($membership, $freeze)
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.ends_on', '2026-11-03')
        ->assertJsonPath('data.freezes.0.ends_on', '2026-10-22')
        ->assertJsonPath('data.freeze_days_used', 3);
});

it('removes a freeze that has not started when it is ended', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 30]);
    actingAsOrganization($this->gym);
    $freeze = $membership->freeze(CarbonImmutable::parse('2026-10-25'), CarbonImmutable::parse('2026-10-27'), 'Travelling.', Staff::factory()->for($this->gym)->create());
    actingAsStaff($this->gym, StaffRole::Receptionist);

    endMembershipFreeze($membership, $freeze)
        ->assertOk()
        ->assertJsonPath('data.ends_on', '2026-10-31')
        ->assertJsonPath('data.freezes', []);
});

it('refuses to end a freeze that is already over', function () {
    $membership = Membership::factory()->for($this->member)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'freeze_days_allowed' => 30]);
    actingAsOrganization($this->gym);
    $freeze = $membership->freeze(CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-12'), 'Ill.', Staff::factory()->for($this->gym)->create());
    actingAsStaff($this->gym, StaffRole::Receptionist);

    endMembershipFreeze($membership, $freeze)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['freeze' => 'This freeze has already ended.']);
});

it('cannot end a freeze of another membership', function () {
    $membership = Membership::factory()->for($this->member)->create();
    $otherMembershipsFreeze = MembershipFreeze::factory()->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    endMembershipFreeze($membership, $otherMembershipsFreeze)->assertNotFound();
});
