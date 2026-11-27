<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Insights\GymInsights;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Tuesday 24 November 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create();
    actingAsOrganization($this->gym);
    $this->insights = app(GymInsights::class);
});

it('counts members let in by weekday and hour where the gym is, at the branches given', function () {
    // Monday 23 November, 06:30 and 06:45 in Addis Ababa (03:30 and 03:45 UTC).
    CheckIn::factory()->for($this->hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-23 06:30', 'Africa/Addis_Ababa')->utc()]);
    CheckIn::factory()->for($this->hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-23 06:45', 'Africa/Addis_Ababa')->utc()]);
    // Sunday 22 November, 18:10.
    CheckIn::factory()->for($this->hana)->for($this->bole)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-22 18:10', 'Africa/Addis_Ababa')->utc()]);
    CheckIn::factory()->for($this->hana)->for($this->main)->denied(CheckInDenial::MembershipExpired)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-23 07:00', 'Africa/Addis_Ababa')->utc()]);
    // Before the three months covered.
    CheckIn::factory()->for($this->hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-08-31 06:30', 'Africa/Addis_Ababa')->utc()]);

    $all = $this->insights->busyHours($this->gym, 3, null);
    $main = $this->insights->busyHours($this->gym, 3, [$this->main->id]);

    expect($all['total'])->toBe(3)
        ->and($all['grid'][1][6])->toBe(2)
        ->and($all['grid'][7][18])->toBe(1)
        ->and($all['grid'][1][7])->toBe(0)
        ->and(array_keys($all['grid']))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($all['grid'][3])->toHaveCount(24)
        ->and($main['total'])->toBe(2);
});

it('gives active and new members each month, and how fully visit packs were used', function () {
    $this->hana->forceFill(['created_at' => CarbonImmutable::parse('2026-10-05 09:00', 'Africa/Addis_Ababa')->utc()])->save();
    Membership::factory()->for($this->hana)->create(['starts_on' => '2026-10-05', 'ends_on' => '2026-12-04']);

    $abebe = Member::factory()->for($this->main, 'homeBranch')->create(['created_at' => CarbonImmutable::parse('2026-09-30 23:30', 'Africa/Addis_Ababa')->utc()]);
    $pack = Membership::factory()->for($abebe)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-31', 'visit_limit' => 10]);
    foreach (['2026-09-02', '2026-09-03', '2026-09-03', '2026-10-01'] as $day) {
        CheckIn::factory()->for($abebe)->for($this->main)->create(['membership_id' => $pack->id, 'checked_in_at' => CarbonImmutable::parse("{$day} 07:00", 'Africa/Addis_Ababa')->utc()]);
    }

    Member::factory()->for($this->bole, 'homeBranch')->create(['created_at' => CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Addis_Ababa')->utc()]);

    $months = collect($this->insights->members($this->gym, 3, [$this->main->id]))->keyBy('month');

    expect($months['2026-09'])->toMatchArray(['active' => 1, 'new' => 1, 'packs_ended' => 0])
        ->and($months['2026-10'])->toMatchArray(['active' => 2, 'new' => 1, 'packs_ended' => 1, 'visits_bought' => 10, 'visits_used' => 3, 'packs_with_visits_left' => 1])
        ->and($months['2026-11'])->toMatchArray(['active' => 1, 'new' => 0, 'packs_ended' => 0]);
});
