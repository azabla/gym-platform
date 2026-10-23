<?php

declare(strict_types=1);

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('works out the last day of a membership', function (int $durationCount, DurationUnit $durationUnit, string $startsOn, string $endsOn) {
    $plan = Plan::factory()->make(['duration_count' => $durationCount, 'duration_unit' => $durationUnit]);

    expect($plan->endsOnFor(CarbonImmutable::parse($startsOn))->toDateString())->toBe($endsOn);
})->with([
    'a day pass' => [1, DurationUnit::Day, '2026-03-01', '2026-03-01'],
    '30 days' => [30, DurationUnit::Day, '2026-03-01', '2026-03-30'],
    'a month' => [1, DurationUnit::Month, '2026-03-15', '2026-04-14'],
    'a month from the 28th of January' => [1, DurationUnit::Month, '2026-01-28', '2026-02-27'],
    'a month into a shorter month' => [1, DurationUnit::Month, '2026-01-31', '2026-02-28'],
    'a month into February of a leap year' => [1, DurationUnit::Month, '2028-01-31', '2028-02-29'],
    '3 months' => [3, DurationUnit::Month, '2026-11-30', '2027-02-28'],
    'a year' => [12, DurationUnit::Month, '2026-10-23', '2027-10-22'],
]);

it('keeps its price as money', function () {
    $plan = Plan::factory()->create(['price' => Money::of('2,500.50')]);

    expect($plan->refresh()->price)->toEqual(Money::of('2500.50'));
});

it('refuses a negative price or a duration of zero', function (array $attributes) {
    Plan::factory()->create($attributes);
})->with([
    'a negative price' => [['price' => Money::of('-1')]],
    'a duration of zero' => [['duration_count' => 0]],
])->throws(QueryException::class, 'violates check constraint');

it('allows each plan name once per gym, whatever the capitals', function () {
    $organization = Organization::factory()->create();
    Plan::factory()->for($organization)->create(['name' => 'Monthly']);

    Plan::factory()->for($organization)->create(['name' => 'MONTHLY']);
})->throws(UniqueConstraintViolationException::class);

it('frees the name of an archived plan', function () {
    $organization = Organization::factory()->create();
    Plan::factory()->for($organization)->create(['name' => 'Monthly'])->delete();

    $newPlan = Plan::factory()->for($organization)->create(['name' => 'Monthly']);

    expect($newPlan->exists)->toBeTrue();
});

it('lets different gyms use the same plan name', function () {
    Plan::factory()->create(['name' => 'Monthly']);
    Plan::factory()->create(['name' => 'Monthly']);

    expect(Plan::query()->acrossOrganizations()->count())->toBe(2);
});

it('can be limited to some branches, and made available at all of them again', function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $bole = Branch::factory()->for($organization)->create();
    Branch::factory()->for($organization)->create();
    $plan = Plan::factory()->for($organization)->create();

    $plan->makeAvailableOnlyAt($bole);

    expect($plan->refresh()->available_at_all_branches)->toBeFalse()
        ->and($plan->branches->modelKeys())->toBe([$bole->id]);

    $plan->makeAvailableAtAllBranches();

    expect($plan->refresh()->available_at_all_branches)->toBeTrue()
        ->and($plan->branches)->toBeEmpty();
});

it('refuses a branch from another gym', function () {
    $plan = Plan::factory()->create();
    $otherGymsBranch = Branch::factory()->create();

    $plan->makeAvailableOnlyAt($otherGymsBranch);
})->throws(QueryException::class, 'violates foreign key constraint');
