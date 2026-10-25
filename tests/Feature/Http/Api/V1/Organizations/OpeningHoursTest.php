<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchOpeningHour;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // Saturday 24 October 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->hana = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    $this->membership = Membership::factory()->for($this->hana)->create();
});

/**
 * Monday to Saturday from 06:00 to 21:00; closed on Sunday.
 *
 * @return list<array{weekday: int, opens_at: string, closes_at: string}>
 */
function mondayToSaturday(): array
{
    return array_map(fn (int $weekday): array => ['weekday' => $weekday, 'opens_at' => '06:00', 'closes_at' => '21:00'], range(1, 6));
}

function openingHoursUrl(Branch $branch, string $action = 'update'): string
{
    return route("api.v1.organizations.branches.opening-hours.{$action}", ['organization' => 'demo-gym', 'branch' => $branch]);
}

/**
 * Give the branch Monday to Saturday hours, as the database keeps them.
 */
function openMondayToSaturday(Branch $branch): void
{
    foreach (range(1, 6) as $weekday) {
        BranchOpeningHour::factory()->for($branch)->create(['weekday' => $weekday, 'opens_at' => '06:00:00', 'closes_at' => '21:00:00']);
    }
}

function checkInAt(Member $member, Branch $branch, string $localTime): TestResponse
{
    test()->travelTo(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa'));

    return test()->postJson(
        route('api.v1.organizations.members.check-ins.store', ['organization' => 'demo-gym', 'member' => $member]),
        ['branch_id' => $branch->id],
    );
}

it('lets an owner set a branch\'s week, which every staff member can see', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->putJson(openingHoursUrl($this->mainBranch), ['hours' => mondayToSaturday()])
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('data.0', ['weekday' => 1, 'opens_at' => '06:00', 'closes_at' => '21:00']);

    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(openingHoursUrl($this->mainBranch, 'show'))
        ->assertOk()
        ->assertJsonCount(6, 'data');
    $this->getJson(route('api.v1.organizations.branches.show', ['organization' => 'demo-gym', 'branch' => $this->mainBranch]))
        ->assertJsonPath('data.opening_hours.5', ['weekday' => 6, 'opens_at' => '06:00', 'closes_at' => '21:00']);
});

it('replaces the whole week, and an empty week makes the branch always open again', function () {
    openMondayToSaturday($this->mainBranch);
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->putJson(openingHoursUrl($this->mainBranch), ['hours' => [['weekday' => 7, 'opens_at' => '08:00', 'closes_at' => '12:00']]])
        ->assertOk()
        ->assertJsonPath('data', [['weekday' => 7, 'opens_at' => '08:00', 'closes_at' => '12:00']]);

    $this->putJson(openingHoursUrl($this->mainBranch), ['hours' => []])
        ->assertOk()
        ->assertJsonPath('data', []);

    checkInAt($this->hana, $this->mainBranch, '2026-10-24 03:00')->assertJsonPath('data.decision', 'allow');
});

it('lets only owners and managers who work at the branch set its hours', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->putJson(openingHoursUrl($this->mainBranch), ['hours' => mondayToSaturday()])->assertForbidden();

    actingAsStaff($this->gym, StaffRole::Manager, $this->boleBranch);
    $this->putJson(openingHoursUrl($this->mainBranch), ['hours' => mondayToSaturday()])->assertForbidden();

    expect(BranchOpeningHour::query()->acrossOrganizations()->count())->toBe(0);
});

it('checks the week sent', function (array $hours, string $field) {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->putJson(openingHoursUrl($this->mainBranch), ['hours' => $hours])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no such weekday' => [[['weekday' => 8, 'opens_at' => '06:00', 'closes_at' => '21:00']], 'hours.0.weekday'],
    'a weekday twice' => [[['weekday' => 1, 'opens_at' => '06:00', 'closes_at' => '12:00'], ['weekday' => 1, 'opens_at' => '14:00', 'closes_at' => '21:00']], 'hours.1.weekday'],
    'closing before opening' => [[['weekday' => 1, 'opens_at' => '21:00', 'closes_at' => '06:00']], 'hours.0.closes_at'],
    'not a time' => [[['weekday' => 1, 'opens_at' => '6am', 'closes_at' => '21:00']], 'hours.0.opens_at'],
    'no week at all' => [['nothing'], 'hours.0.weekday'],
]);

it('turns members away outside the branch\'s hours, where the gym is', function () {
    openMondayToSaturday($this->mainBranch);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    checkInAt($this->hana, $this->mainBranch, '2026-10-24 05:59')->assertJsonPath('data.reason_code', 'OUTSIDE_HOURS');
    checkInAt($this->hana, $this->mainBranch, '2026-10-24 06:00')->assertJsonPath('data.decision', 'allow');
    checkInAt($this->hana, $this->mainBranch, '2026-10-24 21:00')->assertJsonPath('data.reason_code', 'OUTSIDE_HOURS');
    checkInAt($this->hana, $this->mainBranch, '2026-10-25 10:00')->assertJsonPath('data.reason_code', 'OUTSIDE_HOURS');
    checkInAt($this->hana, $this->boleBranch, '2026-10-25 10:10')->assertJsonPath('data.decision', 'allow');
});

it('never uses a visit of a pack when the branch is closed', function () {
    openMondayToSaturday($this->mainBranch);
    $this->membership->forceFill(['visit_limit' => 1])->save();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    checkInAt($this->hana, $this->mainBranch, '2026-10-24 05:00')->assertJsonPath('data.reason_code', 'OUTSIDE_HOURS');
    checkInAt($this->hana, $this->mainBranch, '2026-10-26 07:00')->assertJsonPath('data.decision', 'allow');
});

it('shows the members app every branch with its hours', function () {
    openMondayToSaturday($this->mainBranch);
    actingAsMember($this->hana);

    $this->getJson(route('api.v1.me.gyms.branches.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Bole Branch', 'Main Branch'])
        ->assertJsonPath('data.0.opening_hours', [])
        ->assertJsonCount(6, 'data.1.opening_hours');
});
