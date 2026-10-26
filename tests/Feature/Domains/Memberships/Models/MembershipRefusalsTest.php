<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $organization = $this->organization = actingAsOrganization(Organization::factory()->create());
    $this->member = Member::factory()->for(Branch::factory()->for($organization), 'homeBranch')->create();
    $this->plan = Plan::factory()->for($organization)->create(['duration_count' => 1, 'duration_unit' => DurationUnit::Month, 'freeze_days_allowed' => 7]);
    $this->membership = Membership::factory()->for($this->member)->for($this->plan)->create([
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-31',
        'freeze_days_allowed' => 7,
    ]);
    $this->today = CarbonImmutable::parse('2026-03-10');
});

it('refuses a sale that overlaps another membership, naming the first free day', function () {
    $refusal = Membership::refusalToSell($this->member, $this->plan, CarbonImmutable::parse('2026-03-20'), $this->today);

    expect($refusal?->field)->toBe('starts_on')
        ->and($refusal?->getMessage())->toContain('The first free start date is 1 April 2026');

    expect(Membership::refusalToSell($this->member, $this->plan, CarbonImmutable::parse('2026-04-01'), $this->today))->toBeNull();
});

it('refuses a freeze the membership doesn\'t allow', function (string $firstDay, string $lastDay, string $field) {
    MembershipFreeze::factory()->for($this->membership)->create(['starts_on' => '2026-03-25', 'ends_on' => '2026-03-26']);

    $refusal = $this->membership->refusalToFreeze(CarbonImmutable::parse($firstDay), CarbonImmutable::parse($lastDay), false);

    expect($refusal?->field)->toBe($field);
})->with([
    'ending before it starts' => ['2026-03-10', '2026-03-09', 'ends_on'],
    'longer than a year' => ['2026-03-10', '2027-03-11', 'ends_on'],
    'starting after the membership' => ['2026-04-10', '2026-04-11', 'starts_on'],
    'overlapping another freeze' => ['2026-03-24', '2026-03-25', 'starts_on'],
    'beyond the freeze days left' => ['2026-03-10', '2026-03-15', 'ends_on'],
]);

it('lets owners and managers freeze beyond the allowance', function () {
    expect($this->membership->refusalToFreeze(CarbonImmutable::parse('2026-03-10'), CarbonImmutable::parse('2026-03-20'), false))->not->toBeNull()
        ->and($this->membership->refusalToFreeze(CarbonImmutable::parse('2026-03-10'), CarbonImmutable::parse('2026-03-20'), true))->toBeNull();
});

it('refuses to end a freeze that is over, or of a cancelled membership', function () {
    $over = MembershipFreeze::factory()->for($this->membership)->create(['starts_on' => '2026-03-02', 'ends_on' => '2026-03-03']);
    $coming = MembershipFreeze::factory()->for($this->membership)->create(['starts_on' => '2026-03-20', 'ends_on' => '2026-03-21']);

    expect($this->membership->refusalToEndFreeze($over, $this->today)?->field)->toBe('freeze')
        ->and($this->membership->refusalToEndFreeze($coming, $this->today))->toBeNull();

    $this->membership->cancel(Staff::factory()->for($this->organization)->create(), 'Moved away.');

    expect($this->membership->refusalToEndFreeze($coming, $this->today)?->getMessage())->toBe('This membership is cancelled.');
});

it('refuses to cancel a membership that has ended or is cancelled', function () {
    expect($this->membership->refusalToCancel($this->today))->toBeNull()
        ->and($this->membership->refusalToCancel(CarbonImmutable::parse('2026-04-01'))?->getMessage())->toBe('This membership has already ended.');

    $this->membership->cancel(Staff::factory()->for($this->organization)->create(), 'Moved away.');

    expect($this->membership->refusalToCancel($this->today)?->getMessage())->toBe('This membership is already cancelled.');
});
