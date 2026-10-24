<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
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
    $this->travelTo(CarbonImmutable::parse('2026-10-24 07:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->hana = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    $this->threeVisits = Plan::factory()->for($this->gym)->create([
        'name' => '3 Visits',
        'price' => Money::of('600'),
        'duration_count' => 30,
        'duration_unit' => DurationUnit::Day,
        'visit_limit' => 3,
    ]);
    $this->membership = Membership::factory()->for($this->hana)->for($this->threeVisits)->create(['visit_limit' => 3]);
});

/**
 * Check Hana in at the desk at the branch, as the receptionist.
 */
function checkInHana(Branch $branch): TestResponse
{
    return test()->postJson(
        route('api.v1.organizations.members.check-ins.store', ['organization' => 'demo-gym', 'member' => test()->hana]),
        ['branch_id' => $branch->id],
    );
}

/**
 * Move to the given time where the gym is.
 */
function atGymTime(string $localTime): void
{
    test()->travelTo(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa'));
}

it('creates a visit pack, and checks its limit', function () {
    actingAsStaff($this->gym, StaffRole::Owner);
    $details = ['name' => '10 Visits', 'price' => '1,800', 'duration_count' => 60, 'duration_unit' => 'day'];

    $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), [...$details, 'visit_limit' => 10])
        ->assertCreated()
        ->assertJsonPath('data.visit_limit', 10);

    foreach ([0, 1001, 'ten'] as $badLimit) {
        $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), [...$details, 'name' => 'Other', 'visit_limit' => $badLimit])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('visit_limit');
    }
});

it('copies the limit onto the membership sold, so changing the plan later changes nothing sold', function () {
    actingAsStaff($this->gym, StaffRole::Manager);
    $tigist = Member::factory()->for($this->mainBranch, 'homeBranch')->create();

    $this->postJson(route('api.v1.organizations.members.memberships.store', ['organization' => 'demo-gym', 'member' => $tigist]), [
        'plan_id' => $this->threeVisits->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.visit_limit', 3);

    $this->patchJson(route('api.v1.organizations.plans.update', ['organization' => 'demo-gym', 'plan' => $this->threeVisits]), ['visit_limit' => 5])
        ->assertOk()
        ->assertJsonPath('data.visit_limit', 5);

    expect(Membership::query()->whereBelongsTo($tigist)->sole()->visit_limit)->toBe(3);
});

it('lets the member in once a day until every visit is used', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-25 18:00');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-27 06:30');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');

    atGymTime('2026-10-28 07:00');
    checkInHana($this->mainBranch)
        ->assertJsonPath('data.decision', 'deny')
        ->assertJsonPath('data.reason_code', 'VISITS_USED_UP');
});

it('counts coming back the same day, at any branch, as the same visit', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    atGymTime('2026-10-24 07:00');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-25 07:00');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-26 07:00');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');

    atGymTime('2026-10-26 19:00');
    checkInHana($this->boleBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-27 07:00');
    checkInHana($this->boleBranch)->assertJsonPath('data.reason_code', 'VISITS_USED_UP');
});

it('counts days where the gym is, not in UTC', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->membership->forceFill(['visit_limit' => 2])->save();

    atGymTime('2026-10-24 23:30');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-25 00:30');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');

    atGymTime('2026-10-25 02:30');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-26 06:00');
    checkInHana($this->mainBranch)->assertJsonPath('data.reason_code', 'VISITS_USED_UP');
});

it('never uses a visit for a check-in that was turned away', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->membership->forceFill(['visit_limit' => 1])->save();
    CheckIn::factory()->for($this->hana)->for($this->mainBranch)->for($this->membership)
        ->denied(CheckInDenial::BranchNotAllowed)
        ->create(['checked_in_at' => CarbonImmutable::now()->subDay()]);

    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
});

it('leaves memberships without a limit as they were', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->membership->forceFill(['visit_limit' => null])->save();

    foreach (['2026-10-24 07:00', '2026-10-25 07:00', '2026-10-26 07:00', '2026-10-27 07:00', '2026-10-28 07:00'] as $day) {
        atGymTime($day);
        checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    }
});

it('shows staff the visits used, and the member the visits left', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');
    atGymTime('2026-10-25 07:00');
    checkInHana($this->mainBranch)->assertJsonPath('data.decision', 'allow');

    $this->getJson(route('api.v1.organizations.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->membership]))
        ->assertOk()
        ->assertJsonPath('data.visit_limit', 3)
        ->assertJsonPath('data.visits_used', 2);

    actingAsMember($this->hana);

    $this->getJson(route('api.v1.me.gyms.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->membership]))
        ->assertOk()
        ->assertJsonPath('data.visits_remaining', 1);
    $this->getJson(route('api.v1.me.gyms.dashboard', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.membership.visits_remaining', 1);
});

it('shows no visits left for a membership without a limit', function () {
    $this->membership->forceFill(['visit_limit' => null])->save();
    actingAsMember($this->hana);

    $this->getJson(route('api.v1.me.gyms.memberships.show', ['organization' => 'demo-gym', 'membership' => $this->membership]))
        ->assertOk()
        ->assertJsonPath('data.visits_remaining', null);
});

it('puts a pack with one visit left on the dashboard\'s list of members to call', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(route('api.v1.organizations.dashboard', ['organization' => 'demo-gym']))
        ->assertJsonCount(0, 'data.expiring');

    checkInHana($this->mainBranch);
    atGymTime('2026-10-25 07:00');
    checkInHana($this->mainBranch);

    $this->getJson(route('api.v1.organizations.dashboard', ['organization' => 'demo-gym']))
        ->assertJsonCount(1, 'data.expiring')
        ->assertJsonPath('data.expiring.0.membership_id', $this->membership->id)
        ->assertJsonPath('data.expiring.0.visits_remaining', 1);
});

it('tells the member once when one visit is left', function () {
    fakePushes();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    checkInHana($this->mainBranch);
    expect($this->hana->notifications()->count())->toBe(0);

    atGymTime('2026-10-25 07:00');
    checkInHana($this->mainBranch);
    atGymTime('2026-10-25 19:00');
    checkInHana($this->boleBranch);

    $message = $this->hana->notifications()->sole();
    expect($message->type)->toBe('visits_running_low')
        ->and($message->data['data'])->toBe(['membership_id' => (string) $this->membership->id]);

    atGymTime('2026-10-26 07:00');
    checkInHana($this->mainBranch);
    expect($this->hana->notifications()->count())->toBe(1);
});
