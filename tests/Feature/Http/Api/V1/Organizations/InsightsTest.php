<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Tuesday 24 November 2026, where the gym is.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create();
    $this->bole = Branch::factory()->for($this->gym)->create();
    $hana = Member::factory()->for($this->main, 'homeBranch')->create();
    $membership = Membership::factory()->for($hana)->for(Plan::factory()->for($this->gym)->create(['name' => 'Monthly']))->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    Payment::factory()->for($membership)->create(['amount' => Money::of('2,500'), 'paid_on' => '2026-11-01', 'branch_id' => $this->main->id]);
    Payment::factory()->for($membership)->create(['amount' => Money::of('700'), 'paid_on' => '2026-11-02', 'branch_id' => $this->bole->id]);
    CheckIn::factory()->for($hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-23 06:30', 'Africa/Addis_Ababa')->utc()]);
});

function insightUrl(string $insight, string $query = ''): string
{
    return route('api.v1.organizations.insights.show', ['organization' => 'demo-gym', 'insight' => $insight]).$query;
}

it('gives revenue by month to the roles that see money', function () {
    actingAsStaff($this->gym, StaffRole::Accountant);

    $this->getJson(insightUrl('revenue', '?months=3'))
        ->assertOk()
        ->assertJsonPath('data.insight', 'revenue')
        ->assertJsonPath('data.months.*.month', ['2026-09', '2026-10', '2026-11'])
        ->assertJsonPath('data.months.2.net.formatted', '3,200.00 ETB')
        ->assertJsonPath('data.months.2.by_plan.Monthly.formatted', '3,200.00 ETB')
        ->assertJsonPath('data.months.2.by_method.cash.formatted', '3,200.00 ETB');

    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->getJson(insightUrl('revenue'))->assertForbidden();
});

it('gives the other insights to owners and managers only', function (string $insight) {
    actingAsStaff($this->gym, StaffRole::Manager);
    $this->getJson(insightUrl($insight))->assertOk()->assertJsonPath('data.insight', $insight);

    actingAsStaff($this->gym, StaffRole::Accountant);
    $this->getJson(insightUrl($insight))->assertForbidden();
})->with(['renewals', 'busy-hours', 'members']);

it('covers only the staff member\'s branches, and refuses another period or branch', function () {
    actingAsStaff($this->gym, StaffRole::Manager, $this->bole);

    $this->getJson(insightUrl('revenue', '?months=3'))->assertJsonPath('data.months.2.net.formatted', '700.00 ETB');
    $this->getJson(insightUrl('busy-hours'))->assertJsonPath('data.total', 0);
    $this->getJson(insightUrl('revenue', '?branch_id='.$this->main->id))->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    $this->getJson(insightUrl('revenue', '?months=5'))->assertUnprocessable()->assertJsonValidationErrors('months');
    $this->getJson(insightUrl('no-such-insight'))->assertNotFound();
});

it('downloads an insight as a CSV file', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $response = $this->get(route('api.v1.organizations.reports.insights', ['organization' => 'demo-gym', 'insight' => 'revenue', 'months' => 3]))
        ->assertOk()
        ->assertDownload('demo-gym-revenue-last-3-months.csv');
    $rows = csvRowsOf($response);

    expect($rows[0])->toBe(['Month', 'Net', 'Cash', 'telebirr', 'CBE Birr', 'Bank transfer', 'Chapa', 'Monthly'])
        ->and($rows[3])->toBe(['2026-11', '3200.00', '3200.00', '0.00', '0.00', '0.00', '0.00', '3200.00']);

    $hours = csvRowsOf($this->get(route('api.v1.organizations.reports.insights', ['organization' => 'demo-gym', 'insight' => 'busy-hours'])));

    expect($hours[0][7])->toBe('06:00')
        ->and($hours[1][0])->toBe('Monday')
        ->and($hours[1][7])->toBe('1');
});
