<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Saturday 24 October 2026, 07:00 in Addis Ababa.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 07:00', 'Africa/Addis_Ababa'));
    fakePushes();

    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create();
    $this->abebe = Member::factory()->for($this->bole, 'homeBranch')->create();
});

/**
 * Run the reminders at the given time where the gym is.
 */
function remindOfClosuresAt(string $localTime): void
{
    test()->travelTo(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa'));
    test()->artisan('notifications:send-closure-reminders')->assertSuccessful();
}

it('reminds the branch\'s members at 09:00 the day before, once', function () {
    $closure = BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-10', 'reason' => 'Repairs']);

    remindOfClosuresAt('2026-11-09 08:00');
    expect($this->hana->notifications()->count())->toBe(0);

    remindOfClosuresAt('2026-11-09 09:00');
    remindOfClosuresAt('2026-11-09 10:00');

    expect($this->hana->notifications()->sole()->type)->toBe('branch_closed')
        ->and($this->abebe->notifications()->count())->toBe(0)
        ->and($closure->refresh()->reminded_at)->not->toBeNull()
        ->and($closure->members_told_at)->not->toBeNull();
});

it('doesn\'t remind members told in the last 2 days, when the closure was added', function () {
    actingAsOrganization($this->gym);
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-10-25'), CarbonImmutable::parse('2026-10-25'), 'Holiday');

    remindOfClosuresAt('2026-10-24 09:00');

    expect($this->hana->notifications()->count())->toBe(1);
});

it('reminds members told long ago, when the closure was added', function () {
    actingAsOrganization($this->gym);
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-11-05'), CarbonImmutable::parse('2026-11-05'), 'Timket', '08:00', '12:00');

    remindOfClosuresAt('2026-11-04 09:30');

    expect($this->hana->notifications()->pluck('type')->all())->toBe(['branch_hours_changed', 'branch_hours_changed']);
});

it('tells members who knew of a closure that the branch is open after all, when it is taken back', function () {
    actingAsOrganization($this->gym);
    [$told] = BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-27'), 'Repairs')->all();
    [$farOff] = BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-12-20'), CarbonImmutable::parse('2026-12-20'), 'Meeting')->all();

    $told->refresh()->takeBack();
    $farOff->refresh()->takeBack();

    $reopened = $this->hana->notifications()->where('type', 'branch_reopened')->sole();

    expect($reopened->data['details'])->toMatchArray(['branch' => 'Main Branch', 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-27', 'count' => 2])
        ->and($this->abebe->notifications()->count())->toBe(0)
        ->and(BranchClosure::query()->count())->toBe(0)
        ->and(trans_choice('notifications.branch_reopened.body', 2, $reopened->data['details']))
        ->toBe('Main Branch at Demo Gym is open as usual from 2026-10-26 to 2026-10-27 after all.');
});

it('says nothing when a closure that already ended is taken back', function () {
    $ended = BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-21', 'members_told_at' => now()->subDays(5)]);
    actingAsOrganization($this->gym);

    $ended->takeBack();

    expect($this->hana->notifications()->count())->toBe(0);
});
