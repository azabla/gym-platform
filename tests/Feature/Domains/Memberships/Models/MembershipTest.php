<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

it('is sold with the dates and price of the plan', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $member = Member::factory()->for(Branch::factory()->for($organization), 'homeBranch')->create();
    $plan = Plan::factory()->for($organization)->create([
        'price' => Money::of('2,500'),
        'duration_count' => 1,
        'duration_unit' => DurationUnit::Month,
    ]);
    $seller = Staff::factory()->for($organization)->create();

    $membership = Membership::sell($member, $plan, CarbonImmutable::parse('2026-03-15'), $seller)->refresh();

    expect($membership->organization_id)->toBe($organization->id)
        ->and($membership->starts_on->toDateString())->toBe('2026-03-15')
        ->and($membership->ends_on->toDateString())->toBe('2026-04-14')
        ->and($membership->price)->toEqual(Money::of('2,500'))
        ->and($membership->soldBy->is($seller))->toBeTrue();
});

it('is upcoming, active or expired depending on the day', function (string $day, MembershipStatus $status) {
    $membership = Membership::factory()->make(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);

    expect($membership->statusOn(CarbonImmutable::parse($day)))->toBe($status);
})->with([
    'before it starts' => ['2026-02-28', MembershipStatus::Upcoming],
    'on its first day' => ['2026-03-01', MembershipStatus::Active],
    'on its last day' => ['2026-03-31', MembershipStatus::Active],
    'after it ends' => ['2026-04-01', MembershipStatus::Expired],
]);

it('is cancelled once cancelled, whatever the day', function () {
    $membership = Membership::factory()->cancelled()->make(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);

    expect($membership->statusOn(CarbonImmutable::parse('2026-03-15')))->toBe(MembershipStatus::Cancelled);
});

it('allows a member only one membership on any day', function () {
    $member = Member::factory()->create();
    Membership::factory()->for($member)->create(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);

    Membership::factory()->for($member)->create(['starts_on' => '2026-03-31', 'ends_on' => '2026-04-29']);
})->throws(QueryException::class, 'violates exclusion constraint');

it('lets the next membership start the day after the last one ends', function () {
    $member = Member::factory()->create();
    Membership::factory()->for($member)->create(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);

    $next = Membership::factory()->for($member)->create(['starts_on' => '2026-04-01', 'ends_on' => '2026-04-30']);

    expect($next->exists)->toBeTrue();
});

it('frees the days of a cancelled membership', function () {
    $member = Member::factory()->create();
    Membership::factory()->for($member)->cancelled()->create(['starts_on' => '2026-03-01', 'ends_on' => '2026-03-31']);

    $replacement = Membership::factory()->for($member)->create(['starts_on' => '2026-03-15', 'ends_on' => '2026-04-14']);

    expect($replacement->exists)->toBeTrue();
});

it('refuses a plan from another gym', function () {
    $member = Member::factory()->create();
    $otherGymsPlan = Plan::factory()->create();

    Membership::factory()->for($member)->for($otherGymsPlan)->create();
})->throws(QueryException::class, 'violates foreign key constraint');

it('refuses a member from another gym', function () {
    $plan = Plan::factory()->create();
    $otherGymsMember = Member::factory()->create();

    Membership::factory()->for($otherGymsMember)->for($plan)->create(['organization_id' => $plan->organization_id]);
})->throws(QueryException::class, 'violates foreign key constraint');

it('refuses an end before the start, a negative price or half a cancellation', function (array $attributes) {
    Membership::factory()->create($attributes);
})->with([
    'an end before the start' => [['starts_on' => '2026-03-15', 'ends_on' => '2026-03-14']],
    'a negative price' => [['price' => Money::of('-1')]],
    'a cancellation without a reason' => [['cancelled_at' => '2026-03-15 10:00:00']],
])->throws(QueryException::class, 'violates check constraint');

it('stays valid at the branches its plan had when it was sold', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $bole = Branch::factory()->for($organization)->create();
    $member = Member::factory()->for($bole, 'homeBranch')->create();
    $plan = Plan::factory()->for($organization)->create();
    $plan->makeAvailableOnlyAt($bole);

    $membership = Membership::sell($member, $plan, CarbonImmutable::parse('2026-03-15'), Staff::factory()->for($organization)->create());
    $plan->makeAvailableAtAllBranches();

    expect($membership->refresh()->valid_at_all_branches)->toBeFalse()
        ->and($membership->branches->modelKeys())->toBe([$bole->id]);
});

it('refuses a price above the list price, or a discount without a reason', function (array $attributes) {
    Membership::factory()->create($attributes);
})->with([
    'a price above the list price' => [['list_price' => Money::of('2,500'), 'price' => Money::of('3,000')]],
    'a discount without a reason' => [['list_price' => Money::of('2,500'), 'price' => Money::of('2,000')]],
])->throws(QueryException::class, 'violates check constraint');
