<?php

declare(strict_types=1);

use App\Domains\Attendance\Enums\CheckInDenial;
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
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);

    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Hana Girma', 'phone' => '0911223344']);
    $this->hanas = Membership::factory()->for($this->hana)->for($monthly)->create([
        'starts_on' => '2026-11-10', 'ends_on' => '2026-12-09', 'created_at' => CarbonImmutable::parse('2026-11-10 09:00', 'Africa/Addis_Ababa'),
    ]);
    Payment::factory()->for($this->hanas)->create(['amount' => Money::of('1,000'), 'branch_id' => $this->main->id]);

    $this->abebe = Member::factory()->for($this->bole, 'homeBranch')->create(['full_name' => 'Abebe Bekele']);
    Membership::factory()->for($this->abebe)->for($monthly)->create([
        'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'created_at' => CarbonImmutable::parse('2026-10-01 09:00', 'Africa/Addis_Ababa'),
    ]);
});

function reportUrl(string $name, array $query = []): string
{
    return route("api.v1.organizations.reports.{$name}", ['organization' => 'demo-gym', ...$query]);
}

it('lists the memberships sold in the period with what was paid and what is owed', function () {
    actingAsStaff($this->gym, StaffRole::Manager);

    $rows = csvRowsOf($this->get(reportUrl('memberships', ['from' => '2026-11-01', 'to' => '2026-11-30']))
        ->assertOk()
        ->assertDownload('demo-gym-memberships-2026-11-01-to-2026-11-30.csv'));

    expect($rows)->toHaveCount(2)
        ->and(array_slice($rows[1], 0, 8))->toBe(['2026-11-10', 'Hana Girma', '+251911223344', 'Main Branch', 'Monthly', '2026-11-10', '2026-12-09', 'Active'])
        ->and(array_slice($rows[1], 11, 3))->toBe(['1000.00', '1500.00', 'ETB']);
});

it('keeps memberships and payments to the roles that see money, and members to owners and managers', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(reportUrl('memberships'))->assertForbidden();
    $this->getJson(reportUrl('members'))->assertForbidden();
    $this->get(reportUrl('check-ins'))->assertOk();

    actingAsStaff($this->gym, StaffRole::Accountant);

    $this->getJson(reportUrl('members'))->assertForbidden();
});

it('lists check-ins of the gym\'s days with times where the gym is', function () {
    actingAsStaff($this->gym, StaffRole::Trainer);
    // 01:30 on 13 November in Addis Ababa, still the 12th in UTC.
    CheckIn::factory()->for($this->hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-12 22:30', 'UTC')]);
    CheckIn::factory()->for($this->abebe)->for($this->bole)->denied(CheckInDenial::MembershipExpired)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-13 06:00', 'UTC')]);
    CheckIn::factory()->for($this->hana)->for($this->main)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-12 20:00', 'UTC')]);

    $rows = csvRowsOf($this->get(reportUrl('check-ins'))->assertOk()->assertDownload('demo-gym-check-ins-2026-11-13.csv'));

    expect(array_slice($rows, 1))->toBe([
        ['2026-11-13 01:30', 'Hana Girma', '+251911223344', 'Main Branch', 'At the desk', 'Let in', ''],
        ['2026-11-13 09:00', 'Abebe Bekele', (string) $this->abebe->phone, 'Bole Branch', 'At the desk', 'Turned away', 'Membership expired'],
    ]);
});

it('lists the gym\'s members by name with their membership and balance, and finds them', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $rows = csvRowsOf($this->get(reportUrl('members'))->assertOk()->assertDownload('demo-gym-members-2026-11-13.csv'));

    expect(array_column(array_slice($rows, 1), 0))->toBe(['Abebe Bekele', 'Hana Girma'])
        ->and(array_slice($rows[2], 5, 5))->toBe(['Monthly', '2026-12-09', 'Active', '1500.00', 'ETB'])
        ->and($rows[1][7])->toBe('Expired');

    $found = csvRowsOf($this->get(reportUrl('members', ['search' => '0911 22'])));

    expect(array_column(array_slice($found, 1), 0))->toBe(['Hana Girma']);
});

it('covers only the branches of a manager limited to some', function () {
    actingAsStaff($this->gym, StaffRole::Manager, $this->bole);
    CheckIn::factory()->for($this->hana)->for($this->main)->create();

    expect(array_column(array_slice(csvRowsOf($this->get(reportUrl('members'))), 1), 0))->toBe(['Abebe Bekele'])
        ->and(csvRowsOf($this->get(reportUrl('memberships', ['from' => '2026-10-01', 'to' => '2026-11-30']))))->toHaveCount(2)
        ->and(csvRowsOf($this->get(reportUrl('check-ins'))))->toHaveCount(1);
});
