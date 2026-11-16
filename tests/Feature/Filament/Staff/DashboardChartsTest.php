<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Widgets\BusyHoursChart;
use App\Filament\Staff\Widgets\CheckInsChart;
use App\Filament\Staff\Widgets\TakingsChart;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

use function Livewire\invade;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']);
    $main = Branch::factory()->for($this->gym)->create();
    $hana = Member::factory()->for($main, 'homeBranch')->create();
    Payment::factory()->for(Membership::factory()->for($hana))->create(['amount' => Money::of('1,000'), 'branch_id' => $main->id, 'paid_on' => '2026-11-12']);
    CheckIn::factory()->for($hana)->for($main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-13 04:15', 'UTC')]);
});

/**
 * The data a chart draws, for the dashboard's filters.
 *
 * @param  class-string  $chart
 * @return array<string, mixed>
 */
function chartData(string $chart, array $pageFilters): array
{
    return invade(Livewire::test($chart, ['pageFilters' => $pageFilters])->instance())->getData();
}

it('draws the takings and check-ins of each day, and the busy hours', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $period = ['from' => '2026-11-11', 'to' => '2026-11-13'];

    $takings = chartData(TakingsChart::class, $period);
    $checkIns = chartData(CheckInsChart::class, $period);
    $hours = chartData(BusyHoursChart::class, $period);

    expect($takings['labels'])->toBe(['2026-11-11', '2026-11-12', '2026-11-13'])
        ->and($takings['datasets'][0]['data'])->toBe([0.0, 1000.0, 0.0])
        ->and($checkIns['datasets'][0]['data'])->toBe([0, 0, 1])
        ->and($hours['labels'][7])->toBe('07:00')
        ->and($hours['datasets'][0]['data'][7])->toBe(1);
});

it('keeps the takings chart from staff who don\'t see money', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    expect(TakingsChart::canView())->toBeFalse()
        ->and(CheckInsChart::canView())->toBeTrue()
        ->and(BusyHoursChart::canView())->toBeTrue();
});
