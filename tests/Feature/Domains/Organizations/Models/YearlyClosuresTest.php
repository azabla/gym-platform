<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Resources\BranchClosures\Pages\ListBranchClosures;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
});

/**
 * Run the yearly repeat at the given time where the gym is.
 */
function repeatYearlyClosuresAt(string $localTime): void
{
    test()->travelTo(CarbonImmutable::parse($localTime, 'Africa/Addis_Ababa'));
    test()->artisan('branch-closures:repeat-yearly')->assertSuccessful();
}

it('adds next year\'s holiday on the same Ethiopian date once it ends, once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00', 'Africa/Addis_Ababa'));
    $timket = BranchClosure::factory()->for($this->main)->create([
        'starts_on' => '2026-01-19', 'ends_on' => '2026-01-20', 'reason' => 'Timket', 'repeats_yearly' => true,
    ]);

    repeatYearlyClosuresAt('2026-01-20 23:00');
    expect(BranchClosure::query()->acrossOrganizations()->count())->toBe(1);

    repeatYearlyClosuresAt('2026-01-21 08:00');
    repeatYearlyClosuresAt('2026-01-21 09:00');

    $next = BranchClosure::query()->acrossOrganizations()->whereKeyNot($timket->id)->sole();

    expect($next->starts_on->toDateString())->toBe('2027-01-19')
        ->and($next->ends_on->toDateString())->toBe('2027-01-20')
        ->and($next->reason)->toBe('Timket')
        ->and($next->repeats_yearly)->toBeTrue()
        ->and($next->members_told_at)->toBeNull()
        ->and($next->ethiopianStart())->toBe('Tir 11')
        ->and($timket->refresh()->repeated_at)->not->toBeNull();
});

it('follows the Ethiopian date when it moves a day on the Gregorian calendar', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-01 10:00', 'Africa/Addis_Ababa'));
    BranchClosure::factory()->for($this->main)->create(['starts_on' => '2027-01-19', 'ends_on' => '2027-01-19', 'repeats_yearly' => true]);

    repeatYearlyClosuresAt('2027-01-21 09:00');

    expect(BranchClosure::query()->acrossOrganizations()->orderByDesc('starts_on')->first()->starts_on->toDateString())->toBe('2028-01-20');
});

it('leaves one-off closures alone, and skips a copy that would overlap another closure', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00', 'Africa/Addis_Ababa'));
    BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-01-07', 'ends_on' => '2026-01-07', 'reason' => 'One-off']);
    $genna = BranchClosure::factory()->for($this->main)->create(['starts_on' => '2026-01-08', 'ends_on' => '2026-01-08', 'reason' => 'Genna', 'repeats_yearly' => true]);
    BranchClosure::factory()->for($this->main)->create(['starts_on' => '2027-01-08', 'ends_on' => '2027-01-08', 'reason' => 'Already added']);

    repeatYearlyClosuresAt('2026-01-10 09:00');

    expect(BranchClosure::query()->acrossOrganizations()->count())->toBe(3)
        ->and($genna->refresh()->repeated_at)->not->toBeNull();
});

it('lets staff mark a closure as every year, in the panel and the API', function () {
    $this->travelTo(CarbonImmutable::parse('2025-12-01 10:00', 'Africa/Addis_Ababa'));
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListBranchClosures::class)
        ->callAction('addClosure', ['branch_ids' => [$this->main->id], 'starts_on' => '2026-01-19', 'ends_on' => '2026-01-19', 'reason' => 'Timket', 'repeats_yearly' => true])
        ->assertHasNoFormErrors();

    expect(BranchClosure::query()->sole()->repeats_yearly)->toBeTrue();

    Livewire::test(ListBranchClosures::class)->assertSee('Yearly, on Tir 11');

    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(route('api.v1.organizations.branch-closures.store', ['organization' => 'demo-gym']), [
        'branch_ids' => [$this->main->id], 'starts_on' => '2026-09-27', 'ends_on' => '2026-09-27', 'reason' => 'Meskel', 'repeats_yearly' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.0.repeats_yearly', true)
        ->assertJsonPath('data.0.ethiopian_date', 'Meskerem 17');
});
