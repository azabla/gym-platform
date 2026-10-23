<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->monthlyPlanDetails = [
        'name' => 'Monthly',
        'description' => 'Gym floor and group classes.',
        'price' => '2,500',
        'duration_count' => 1,
        'duration_unit' => 'month',
    ];
});

it('lists the plans of the gym in alphabetical order, leaving out archived ones', function () {
    Plan::factory()->for($this->gym)->create(['name' => 'Yearly']);
    Plan::factory()->for($this->gym)->create(['name' => 'Monthly']);
    Plan::factory()->for($this->gym)->create(['name' => 'Old Plan'])->delete();
    Plan::factory()->create(['name' => 'Plan Of Another Gym']);
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(route('api.v1.organizations.plans.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Monthly', 'Yearly']);
});

it('creates a plan', function () {
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), $this->monthlyPlanDetails)
        ->assertCreated()
        ->assertJsonPath('data.name', 'Monthly')
        ->assertJsonPath('data.description', 'Gym floor and group classes.')
        ->assertJsonPath('data.price', ['amount_minor' => 250000, 'currency' => 'ETB', 'formatted' => '2,500.00 ETB'])
        ->assertJsonPath('data.duration', ['count' => 1, 'unit' => 'month'])
        ->assertJsonPath('data.available_at_all_branches', true)
        ->assertJsonPath('data.branches', []);

    expect(Plan::query()->acrossOrganizations()->sole()->organization_id)->toBe($this->gym->id);
});

it('limits a plan to chosen branches of the gym', function () {
    $bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), [
        ...$this->monthlyPlanDetails,
        'branch_ids' => [$bole->id],
    ])
        ->assertCreated()
        ->assertJsonPath('data.available_at_all_branches', false)
        ->assertJsonPath('data.branches.*.name', ['Bole Branch']);
});

it('refuses a branch of another gym', function () {
    $otherGymsBranch = Branch::factory()->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), [
        ...$this->monthlyPlanDetails,
        'branch_ids' => [$otherGymsBranch->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['branch_ids.0']);
});

it('makes a plan valid at all branches again', function () {
    $plan = Plan::factory()->for($this->gym)->create();
    $plan->makeAvailableOnlyAt(Branch::factory()->for($this->gym)->create());
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->patchJson(route('api.v1.organizations.plans.update', ['organization' => 'demo-gym', 'plan' => $plan]), [
        'branch_ids' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.available_at_all_branches', true)
        ->assertJsonPath('data.branches', []);
});

it('lets only owners and managers create, edit and archive plans', function (StaffRole $role) {
    $plan = Plan::factory()->for($this->gym)->create();
    actingAsStaff($this->gym, $role);

    $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), $this->monthlyPlanDetails)
        ->assertForbidden();

    $this->patchJson(route('api.v1.organizations.plans.update', ['organization' => 'demo-gym', 'plan' => $plan]), [
        'name' => 'New Name',
    ])->assertForbidden();

    $this->deleteJson(route('api.v1.organizations.plans.destroy', ['organization' => 'demo-gym', 'plan' => $plan]))
        ->assertForbidden();
})->with([StaffRole::Receptionist, StaffRole::Trainer]);

it('reports a bad price or duration', function (array $details, string $field) {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), [...$this->monthlyPlanDetails, ...$details])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'a price sent as a number' => [['price' => 2500], 'price'],
    'a negative price' => [['price' => '-100'], 'price'],
    'a price with three decimals' => [['price' => '10.505'], 'price'],
    'a duration of zero' => [['duration_count' => 0], 'duration_count'],
    'more than 36 months' => [['duration_count' => 37], 'duration_count'],
    'more than 366 days' => [['duration_count' => 367, 'duration_unit' => 'day'], 'duration_count'],
    'an unknown unit' => [['duration_unit' => 'week'], 'duration_unit'],
]);

it('refuses a name another plan of the gym already has, whatever the capitals', function () {
    Plan::factory()->for($this->gym)->create(['name' => 'Monthly']);
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(route('api.v1.organizations.plans.store', ['organization' => 'demo-gym']), [
        ...$this->monthlyPlanDetails,
        'name' => 'MONTHLY',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('changes a plan, and a new price applies only to new sales', function () {
    $plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $member = Member::factory()->for(Branch::factory()->for($this->gym), 'homeBranch')->create();
    $soldMembership = Membership::factory()->for($member)->for($plan)->create();
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->patchJson(route('api.v1.organizations.plans.update', ['organization' => 'demo-gym', 'plan' => $plan]), [
        'price' => '3,000',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Monthly')
        ->assertJsonPath('data.price.amount_minor', 300000);

    expect($soldMembership->refresh()->price)->toEqual(Money::of('2,500'));
});

it('archives a plan, which then leaves the list', function () {
    $plan = Plan::factory()->for($this->gym)->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->deleteJson(route('api.v1.organizations.plans.destroy', ['organization' => 'demo-gym', 'plan' => $plan]))
        ->assertNoContent();

    $this->getJson(route('api.v1.organizations.plans.index', ['organization' => 'demo-gym']))
        ->assertJsonCount(0, 'data');

    expect(Plan::query()->acrossOrganizations()->withTrashed()->sole()->trashed())->toBeTrue();
});

it('shows a plan of the gym, but not one of another gym', function () {
    $plan = Plan::factory()->for($this->gym)->create(['name' => 'Monthly']);
    $otherGymsPlan = Plan::factory()->create();
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(route('api.v1.organizations.plans.show', ['organization' => 'demo-gym', 'plan' => $plan]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Monthly');

    $this->getJson(route('api.v1.organizations.plans.show', ['organization' => 'demo-gym', 'plan' => $otherGymsPlan]))
        ->assertNotFound();
});
