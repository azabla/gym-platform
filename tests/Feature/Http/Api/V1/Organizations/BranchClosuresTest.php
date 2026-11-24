<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Saturday 24 October 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));
    fakePushes();

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
});

function closuresUrl(?BranchClosure $closure = null): string
{
    return $closure === null
        ? route('api.v1.organizations.branch-closures.index', ['organization' => 'demo-gym'])
        : route('api.v1.organizations.branch-closures.destroy', ['organization' => 'demo-gym', 'branch_closure' => $closure]);
}

it('lets an owner close several branches at once, or open them other hours', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(closuresUrl(), [
        'branch_ids' => [$this->main->id, $this->bole->id],
        'starts_on' => '2026-10-26',
        'ends_on' => '2026-10-26',
        'reason' => 'Timket',
        'opens_at' => '08:00',
        'closes_at' => '12:00',
    ])
        ->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.branch.name', ['Bole Branch', 'Main Branch'])
        ->assertJsonPath('data.1', [
            'id' => BranchClosure::query()->where('branch_id', $this->main->id)->value('id'),
            'branch_id' => $this->main->id,
            'branch' => ['id' => $this->main->id, 'name' => 'Main Branch'],
            'starts_on' => '2026-10-26',
            'ends_on' => '2026-10-26',
            'reason' => 'Timket',
            'opens_at' => '08:00',
            'closes_at' => '12:00',
            'repeats_yearly' => false,
            'ethiopian_date' => 'Tikimt 16',
        ]);

    $this->getJson(closuresUrl())->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(closuresUrl().'?branch_id='.$this->bole->id)->assertJsonCount(1, 'data');
});

it('lets only owners and managers close the branches they work at', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->postJson(closuresUrl(), ['branch_ids' => [$this->main->id], 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'reason' => 'Holiday'])
        ->assertForbidden();

    actingAsStaff($this->gym, StaffRole::Manager, $this->bole);
    $this->postJson(closuresUrl(), ['branch_ids' => [$this->main->id, $this->bole->id], 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'reason' => 'Holiday'])
        ->assertForbidden();
    $this->postJson(closuresUrl(), ['branch_ids' => [$this->bole->id], 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'reason' => 'Holiday'])
        ->assertCreated();

    expect(BranchClosure::query()->acrossOrganizations()->pluck('branch_id')->all())->toBe([$this->bole->id]);
});

it('checks the closure sent', function (array $changes, string $field) {
    actingAsStaff($this->gym, StaffRole::Owner);
    BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-02']);

    $this->postJson(closuresUrl(), [
        'branch_ids' => [$this->main->id],
        'starts_on' => '2026-10-26',
        'ends_on' => '2026-10-26',
        'reason' => 'Holiday',
        ...$changes,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no branches' => [['branch_ids' => []], 'branch_ids'],
    'another gym\'s branch' => [['branch_ids' => [999999]], 'branch_ids'],
    'ends before it starts' => [['ends_on' => '2026-10-25'], 'ends_on'],
    'in the past' => [['starts_on' => '2026-10-23'], 'starts_on'],
    'too long' => [['ends_on' => '2026-12-31'], 'ends_on'],
    'over another closure' => [['starts_on' => '2026-11-02', 'ends_on' => '2026-11-03'], 'starts_on'],
    'opening without closing' => [['opens_at' => '08:00'], 'closes_at'],
    'closing when it opens' => [['opens_at' => '08:00', 'closes_at' => '08:00'], 'closes_at'],
    'no reason' => [['reason' => ''], 'reason'],
]);

it('takes a closure back, for staff who may manage its branch', function () {
    $closure = BranchClosure::factory()->for($this->main)->create();

    actingAsStaff($this->gym, StaffRole::Manager, $this->bole);
    $this->deleteJson(closuresUrl($closure))->assertForbidden();

    actingAsStaff($this->gym, StaffRole::Manager, $this->main);
    $this->deleteJson(closuresUrl($closure))->assertNoContent();

    expect(BranchClosure::query()->acrossOrganizations()->count())->toBe(0);
});

it('shows the members app each branch with its closures over the next 60 days', function () {
    BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-10-26', 'ends_on' => '2026-10-27', 'reason' => 'Repairs']);
    BranchClosure::factory()->for($this->main)->create(['starts_on' => '2027-01-19', 'ends_on' => '2027-01-19', 'reason' => 'Timket']);
    actingAsMember(Member::factory()->for($this->main, 'homeBranch')->create());

    $this->getJson(route('api.v1.me.gyms.branches.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.0.closures', [])
        ->assertJsonCount(1, 'data.1.closures')
        ->assertJsonPath('data.1.closures.0.reason', 'Repairs')
        ->assertJsonPath('data.1.closures.0.opens_at', null);
});
