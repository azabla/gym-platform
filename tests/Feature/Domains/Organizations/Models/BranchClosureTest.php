<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Events\BranchClosureScheduled;
use App\Domains\Organizations\Exceptions\BranchClosureRefused;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\BranchOpeningHour;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Saturday 24 October 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
    actingAsOrganization($this->gym);
});

/**
 * Whether the branch is open at the time where the gym is.
 */
function openAt(Branch $branch, string $localTime): bool
{
    return $branch->refresh()->isOpenAt(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa'), 'Africa/Addis_Ababa');
}

it('closes a branch all day, even one that is always open, and only that branch', function () {
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-27'), 'Repairs');

    expect(openAt($this->main, '2026-10-25 23:59'))->toBeTrue()
        ->and(openAt($this->main, '2026-10-26 10:00'))->toBeFalse()
        ->and(openAt($this->main, '2026-10-27 23:00'))->toBeFalse()
        ->and(openAt($this->main, '2026-10-28 00:00'))->toBeTrue()
        ->and(openAt($this->bole, '2026-10-26 10:00'))->toBeTrue();
});

it('opens other hours on a closure\'s days instead of the weekly hours', function () {
    BranchOpeningHour::factory()->for($this->main)->create(['weekday' => 1, 'opens_at' => '06:00:00', 'closes_at' => '21:00:00']);
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-26'), 'Timket', '08:00', '12:00');

    expect(openAt($this->main, '2026-10-26 07:00'))->toBeFalse()
        ->and(openAt($this->main, '2026-10-26 09:00'))->toBeTrue()
        ->and(openAt($this->main, '2026-10-26 13:00'))->toBeFalse();
});

it('cuts the night from the day before short when the next day is closed', function () {
    BranchOpeningHour::factory()->for($this->main)->create(['weekday' => 1, 'opens_at' => '18:00:00', 'closes_at' => '02:00:00']);

    expect(openAt($this->main, '2026-10-27 01:00'))->toBeTrue();

    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-26'), 'Holiday');

    expect(openAt($this->main, '2026-10-26 19:00'))->toBeFalse()
        ->and(openAt($this->main, '2026-10-27 01:00'))->toBeFalse();
});

it('closes several branches at once, one closure each, and announces it', function () {
    Event::fake([BranchClosureScheduled::class]);

    $closures = BranchClosure::schedule($this->owner, [$this->main, $this->bole], CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-10-26'), ' Timket ');

    expect($closures)->toHaveCount(2)
        ->and($closures->pluck('branch_id')->all())->toBe([$this->main->id, $this->bole->id])
        ->and($closures->first()->reason)->toBe('Timket');

    Event::assertDispatched(BranchClosureScheduled::class, fn (BranchClosureScheduled $event): bool => $event->closureIds === $closures->pluck('id')->all());
});

it('refuses a closure in the past, too long, over another, or at a branch the staff member doesn\'t work at', function (Closure $schedule, string $message) {
    BranchClosure::schedule($this->owner, [$this->main], CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-03'), 'Repairs');

    expect(fn () => $schedule($this))->toThrow(BranchClosureRefused::class, $message);
})->with([
    'in the past' => [fn ($test) => BranchClosure::schedule($test->owner, [$test->main], CarbonImmutable::parse('2026-10-23'), CarbonImmutable::parse('2026-10-24'), 'Late'), 'today at the earliest'],
    'too long' => [fn ($test) => BranchClosure::schedule($test->owner, [$test->bole], CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-01-01'), 'Long'), '31 days at most'],
    'over another' => [fn ($test) => BranchClosure::schedule($test->owner, [$test->bole, $test->main], CarbonImmutable::parse('2026-11-03'), CarbonImmutable::parse('2026-11-04'), 'More'), 'Main Branch already has a closure'],
    'not their branch' => [function ($test) {
        $manager = Staff::factory()->for($test->gym)->create(['role' => StaffRole::Manager]);
        $manager->assignToBranches($test->bole);

        return BranchClosure::schedule($manager, [$test->main], CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-01'), 'Mine');
    }, 'You don\'t work at Main Branch'],
]);

it('refuses a closure pointing at another gym\'s branch', function () {
    app(CurrentOrganization::class)->clear();
    $otherGymsBranch = Branch::factory()->create();

    BranchClosure::factory()->create([
        'organization_id' => $this->gym->id,
        'branch_id' => $otherGymsBranch->id,
        'created_by_staff_id' => $this->owner->id,
    ]);
})->throws(QueryException::class, 'violates foreign key constraint');
