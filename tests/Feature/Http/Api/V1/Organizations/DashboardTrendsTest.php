<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $hana = Member::factory()->for($this->main, 'homeBranch')->create();
    $hanas = Membership::factory()->for($hana)->create(['price' => Money::of('5,000'), 'list_price' => Money::of('5,000')]);

    Payment::factory()->for($hanas)->create(['amount' => Money::of('1,000'), 'branch_id' => $this->main->id, 'paid_on' => '2026-11-11']);
    Payment::factory()->for($hanas)->create(['amount' => Money::of('2,000'), 'branch_id' => $this->bole->id, 'paid_on' => '2026-11-13']);
    Payment::factory()->for($hanas)->voided()->create(['amount' => Money::of('700'), 'branch_id' => $this->main->id, 'paid_on' => '2026-11-13']);
    Payment::factory()->for($hanas)->refund()->create(['amount' => Money::of('500'), 'branch_id' => $this->main->id, 'paid_on' => '2026-11-13']);

    // 01:30 and 07:15 on the 13th where the gym is; the first is still the 12th in UTC.
    CheckIn::factory()->for($hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-12 22:30', 'UTC')]);
    CheckIn::factory()->for($hana)->for($this->bole)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-13 04:15', 'UTC')]);
    CheckIn::factory()->for($hana)->for($this->main)->denied(CheckInDenial::MembershipExpired)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-11 05:00', 'UTC')]);
});

function dashboardTrends(array $query = []): string
{
    return route('api.v1.organizations.dashboard', ['organization' => 'demo-gym', 'from' => '2026-11-11', 'to' => '2026-11-13', ...$query]);
}

it('gives the takings of every day of the period, refunds taken off and voids left out', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(dashboardTrends())
        ->assertOk()
        ->assertJsonPath('data.trends.takings_by_day.*.date', ['2026-11-11', '2026-11-12', '2026-11-13'])
        ->assertJsonPath('data.trends.takings_by_day.*.net.amount_minor', [100000, 0, 150000]);
});

it('counts check-ins by the gym\'s day and hour', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $response = $this->getJson(dashboardTrends())
        ->assertOk()
        ->assertJsonMissingPath('data.trends.takings_by_day')
        ->assertJsonPath('data.trends.check_ins_by_day', [
            ['date' => '2026-11-11', 'allowed' => 0, 'denied' => 1],
            ['date' => '2026-11-12', 'allowed' => 0, 'denied' => 0],
            ['date' => '2026-11-13', 'allowed' => 2, 'denied' => 0],
        ]);

    $byHour = collect($response->json('data.trends.check_ins_by_hour'))->pluck('allowed', 'hour');

    expect($byHour)->toHaveCount(24)
        ->and($byHour[1])->toBe(1)
        ->and($byHour[7])->toBe(1)
        ->and($byHour->sum())->toBe(2);
});

it('covers only the branch chosen', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(dashboardTrends(['branch_id' => $this->bole->id]))
        ->assertJsonPath('data.trends.takings_by_day.*.net.amount_minor', [0, 0, 200000])
        ->assertJsonPath('data.trends.check_ins_by_day.2.allowed', 1);
});
